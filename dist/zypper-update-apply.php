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

function parse_httpd_log(&$update_all_patches, &$update_patches, &$update_all_packages, &$update_packages) {
  global $HTTPD_LOG, $RUNFILE, $TASK_PSK;

  if (! file_exists($RUNFILE))
    return false;

  $updateID = trim(file_get_contents($RUNFILE));
  exec(sprintf(CMD_FGREP_LOG, $updateID, $HTTPD_LOG), $entries, $exit);

  if (empty($entries))
    return false;

  print_r($entries);

  $commands = array();
  foreach ($entries as $entry) {
    preg_match("@{$updateID}/([^ ]+)@", $entry, $matches);
    $commands[] = mc_decrypt($matches[1], $TASK_PSK);
  }
  print_r($commands);
  
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


if (parse_httpd_log($update_all_patches, $update_patches, $update_all_packages, $update_packages)) {
  $output = array();

  if (! is_zypper_ready($ready_message)) {
    send_mail($ready_message, '');
    exit;
  }
  
  if ($update_all_patches) {
    $cmd = sprintf(CMD_ZYPPER_UP_PATCH, '');
    $output[] = "# {$cmd}";
    exec($cmd, $output, $exit);
  } else {
    if (! empty($update_patches)) {
      foreach ($update_patches as &$cmd) {
        $cmd = preg_replace('@/[^/]+/[^/]+/@', '', $cmd);
      }
      $cmd = sprintf(CMD_ZYPPER_UP_PATCH, implode(' ', $update_patches));
      $output[] = "# {$cmd}";
      exec($cmd, $output, $exit);
    }
  }
  
  $output[] = '';
  $output[] = '';
  $output[] = '';
  
  if ($update_all_packages) {
    $cmd = sprintf(CMD_ZYPPER_UP_PACKAGE, '');
    $output[] = "# {$cmd}";
    exec($cmd, $output, $exit);
  } else {
    if (! empty($update_packages)) {
      foreach ($update_packages as &$cmd) {
        $cmd = preg_replace('@/[^/]+/[^/]+/@', '', $cmd);
      }
      $cmd = sprintf(CMD_ZYPPER_UP_PACKAGE, implode(' ', $update_packages));
      $output[] = "# {$cmd}";
      exec($cmd, $output, $exit);
    }
  }
  
  $output[] = '';
  $output[] = '';
  $output[] = '';
  
  $output[] = '# ' . CMD_ZYPPER_PS;
  exec(CMD_ZYPPER_PS, $output, $exit);
  print_r($output);

  send_mail(implode("\n", $output), '');
}
?>
