#!/usr/bin/php
<?php
/**
    This file is part of zypper-update-requestor.

    Copyright (C) 2012 Gregor Dschung

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

error_reporting(E_ALL);

if(@php_sapi_name() != 'cli' && @php_sapi_name() != 'cgi' && @php_sapi_name() != 'cgi-fcgi') {
    die('This script will only work in the shell.');
}

require_once 'config.php';
require_once 'common.php';

const MAX_RETRIES          = 4;
const TIME_BETWEEN_RETRIES = 5; // 5x 60 seconds


function is_zypper_ready(&$output) {
  $retry = 0;
  while (file_exists(ZYPP_PID_FILE)) {
    if ($retry == MAX_RETRIES) {
      return false;
    }
    $output .= sprintf("Software management is locked. Retry in %d seconds.\n\n", TIME_BETWEEN_RETRIES);
    sleep(TIME_BETWEEN_RETRIES);
  }
  return true;
}

function parse_httpd_log(&$commands, &$updateIDs, &$update_all_patches, &$update_patches, &$update_all_packages, &$update_packages) {
  global $HTTPD_LOG, $RUNFILE, $TASK_PSK;

  if (! file_exists($RUNFILE))
    return false;

  // trim in order to remove a possible \n
  // filter in order to remove possible empty lines
  $updateIDs = array_filter(array_map('trim', file($RUNFILE)));
  
  $commands = array();

  foreach ($updateIDs as $updateID) {
    $entries = array();
    exec(sprintf(CMD_FGREP_LOG, $updateID, $HTTPD_LOG), $entries, $exit);
    foreach ($entries as $entry) {
      preg_match("@{$updateID}/([^ ]+)@", $entry, $matches);
      $commands[] = mc_decrypt($matches[1], $TASK_PSK);
    }
  }

  if (empty($entries))
    return false;
  
  $cmds_all_patches = preg_grep('@/all-patches@', $commands);
  $update_all_patches = ! empty($cmds_all_patches);
  $update_patches = preg_grep('@/patch/@', $commands);

  $cmds_all_packages = preg_grep('@/all-packages@', $commands);
  $update_all_packages = ! empty($cmds_all_packages);
  $update_packages = preg_grep('@/package/@', $commands);

  return true;
}

function send_mail($message, $header) {
  global $MAIL_ENCRYPTION, $CERTIFICATE_x509, $MAIL_FROM, $MAIL_TO, $MAIL_SUBJECT_APPLY;

  switch ($MAIL_ENCRYPTION) {
    case 'x509':
      $mail_file = tempnam("/tmp", "update-");
      $mail_file_enc = tempnam("/tmp", "update-");
  
      $message_plain = <<<EOT
{$header}

{$message}
EOT;
      file_put_contents($mail_file, trim($message_plain));
  
      // encrypts $mail_file with the PK saved in $certificate_x509 and writes the result
      // to $mail_file_enc
      openssl_pkcs7_encrypt($mail_file, $mail_file_enc, openssl_x509_read($CERTIFICATE_x509), array());
  
      $mail_data = file_get_contents($mail_file_enc);
      list($header, $message) = explode("\n\n", $mail_data, 2);
      $header = <<<EOT
{$header}
From: {$MAIL_FROM}
EOT;
  
      unlink($mail_file);
      unlink($mail_file_enc);
      break;
    case 'none':
      $header = <<<EOT
{$header}
From: {$MAIL_FROM}
EOT;
      // so there can't be a leading blank line:
      $header = trim($header);
      break;
  }

  $hostname = exec(CMD_HOSTNAME);
  mail($MAIL_TO, sprintf($MAIL_SUBJECT_APPLY, $hostname), $message, $header);
}


if (parse_httpd_log($commands, $updateIDs, $update_all_patches, $update_patches, $update_all_packages, $update_packages)) {
  $message = 'The following tasks are applied:\n';
  foreach ($commands as $task) {
    $message .= "  - ${task}\n";
  }
  $message .= "\n\nLogging:\n\n";

  $output = array();

  if (! is_zypper_ready($ready_message)) {
    send_mail($message . $ready_message, '');
    exit;
  }
 
  $exit_patch = 0;
  if ($update_all_patches) {
    $cmd = sprintf(CMD_ZYPPER_UP_PATCH, '');
    $output[] = "# {$cmd}";
    exec($cmd, $output, $exit_patch);
  } else {
    if (! empty($update_patches)) {
      foreach ($update_patches as &$cmd) {
        $cmd = preg_replace('@/[^/]+/[^/]+/@', '', $cmd);
      }
      $cmd = sprintf(CMD_ZYPPER_UP_PATCH, implode(' ', $update_patches));
      $output[] = "# {$cmd}";
      exec($cmd, $output, $exit_patch);
    }
  }
  
  $output[] = '';
  $output[] = '';
  $output[] = '';
  
  $exit_package = 0;
  if ($update_all_packages) {
    $cmd = sprintf(CMD_ZYPPER_UP_PACKAGE, '');
    $output[] = "# {$cmd}";
    exec($cmd, $output, $exit_package);
  } else {
    if (! empty($update_packages)) {
      foreach ($update_packages as &$cmd) {
        $cmd = preg_replace('@/[^/]+/[^/]+/@', '', $cmd);
      }
      $cmd = sprintf(CMD_ZYPPER_UP_PACKAGE, implode(' ', $update_packages));
      $output[] = "# {$cmd}";
      exec($cmd, $output, $exit_package);
    }
  }
  
  $output[] = '';
  $output[] = '';
  $output[] = '';
  
  $output[] = '# ' . CMD_ZYPPER_PS;
  exec(CMD_ZYPPER_PS, $output, $exit_ps);

  if (($exit_patch != 0 && $exit_patch < 100) || $exit_package != 0 || $exit_ps != 0) {
    $output[] = '';
    $output[] = '';
    $output[] = '';
    $output[] = 'At least one of the commands above did not exit with status code 0.' . "$exit_patch $exit_package $exit_ps";
    $output[] = sprintf('UpdateID %s will not be removed from %s, thus the installation can be retried.', implode(', ', $updateIDs), $RUNFILE);
  } else {
    // only the newest $updateID is kept
    $updateIDs_new = array_slice($updateIDs, -1);
    file_put_contents($RUNFILE, implode("\n", $updateIDs_new) . "\n");
  }

  send_mail($message . implode("\n", $output), '');
}
?>
