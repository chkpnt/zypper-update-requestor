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

if ($URL_PREFIX === 'https://example.com/i/' || $MAIL_TO === 'root@example.com' || $MAIL_FROM === 'admin@example.com') {
	die('zypper-update-requestor isn\'t configured yet.');
}

function generate_and_set_updateID() {
  global $updateID, $RUNFILE;

  $updateID = base64_encode(pack('H*', preg_replace('/[^0-9a-fA-F]/', '', uniqid('', true))));
  $updateID = preg_replace('@[/=]@', '', $updateID);

  file_put_contents($RUNFILE, $updateID . "\n", FILE_APPEND);  
}

function generate_confirmation_url($type, $name = null) {
  global $URL_PREFIX, $updateID, $TASK_PSK;

  // we include a small part of the updateID as a salt
  // in order to permutate the encrypted command.
  switch($type) {
    case 'patch':
      $cmd = sprintf('/%s/patch/%s', substr($updateID,-2), $name);
      break;
    case 'package':
      $cmd = sprintf('/%s/package/%s', substr($updateID,-2), $name);
      break;
    case 'all-patches':
      $cmd = sprintf('/%s/all-patches', substr($updateID,-2));
      break;
    case 'all-packages':
      $cmd = sprintf('/%s/all-packages', substr($updateID,-2));
      break;
  }
  
  $cmd_enc = mc_encrypt($cmd, $TASK_PSK);

  $url = $URL_PREFIX . $updateID . '/' . $cmd_enc;

  return $url;
}


function mailbody_patches(&$output_text, &$output_html = null) {
  global $repos_xml;

  $p['name'] = array('Name', '');
  $p['edition'] = array('Version', '');
  $p['category'] = array('Category', '');
  $p['status'] = array('Status', '');
  $p['repo'] = array('Repository', '');
  $p['summary'] = array('Summary', '');
  $p['install'] = array('Link for installation', '');

  exec(CMD_ZYPPER_LU_PATCH, $out, $exit);

  $out_xml = new SimpleXMLElement(implode("\n", $out));
  $updates_xml = $out_xml->xpath('//update');
  while(list( , $update_xml) = each($updates_xml)) {
    $name = (string)$update_xml['name'];
    $p['name'][] = $name;

    $repo_alias_xml = $repos_xml->xpath(sprintf('//repo[@alias="%s"]', (string)$update_xml->source['alias']));
    $p['repo'][] = (string)$repo_alias_xml[0]['name'];
    
    $p['edition'][] = (string)$update_xml['edition'];
    $p['category'][] = (string)$update_xml['category'];
    $p['status'][] = (string)$update_xml['status'];
    $p['summary'][] = trim($update_xml->summary);

    $p['install'][] = generate_confirmation_url('patch', $name);
  }

  // in order to print an ascii-table, max width is needed for each column
  foreach($p as $key => $values) { $width[$key] = max(array_map('strlen', $values)); }
 
  // text:
  for ($i=0; $i < count($p['name']); $i++) {
    if ($i==1)
      $output_text .= sprintf("%'--${width['repo']}s-+-%'--${width['name']}s-+-%'--${width['edition']}s-+-%'--${width['category']}s-+-%'--${width['status']}s-+-%'--${width['summary']}s-+-%'--${width['install']}s\n",
        '', '', '', '', '', '', '');
    else 
      $output_text .= sprintf("%-${width['repo']}s | %-${width['name']}s | %-${width['edition']}s | %-${width['category']}s | %-${width['status']}s | %-${width['summary']}s | %-${width['install']}s\n",
        $p['repo'][$i], $p['name'][$i], $p['edition'][$i], $p['category'][$i], $p['status'][$i], $p['summary'][$i], $p['install'][$i]);
  }
  $output_text .= sprintf("\nInstall all patches: %s", generate_confirmation_url('all-patches'));

  // html:
  if (! is_null($output_html)) {
    $output_html .= "<table>\n";
    for ($i=0; $i < count($p['name']); $i++) {
      if ($i==1)
        continue;

      if ($i==0)
        $output_html .= sprintf("<tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr>\n",
          $p['repo'][$i], $p['name'][$i], $p['edition'][$i], $p['category'][$i], $p['status'][$i], $p['summary'][$i], $p['install'][$i]);
      else 
        $output_html .= sprintf("<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class=\"max-width\">%s</td><td>%s</td></tr>\n",
          $p['repo'][$i], $p['name'][$i], $p['edition'][$i], $p['category'][$i], $p['status'][$i], $p['summary'][$i], sprintf('<a href="%s">install</a>', $p['install'][$i]));
    }
    $output_html .= sprintf("</table>\n<p>All patches: <a href=\"%s\">installation</a></p>\n", generate_confirmation_url('all-patches'));
  }
}

function mailbody_packages(&$output_text, &$output_html = null) {
  global $repos_xml;

  $p['name'] = array('Name', '');
  $p['edition'] = array('Available Version', '');
  $p['edition_current'] = array('Current Version', '');
  $p['arch'] = array('Arch', '');
  $p['repo'] = array('Repository', '');
  $p['install'] = array('Link for installation', '');

  exec(CMD_ZYPPER_LU_PACKAGE, $out, $exit);

  $out_xml = new SimpleXMLElement(implode("\n", $out));
  $updates_xml = $out_xml->xpath('//update');
  while(list( , $update_xml) = each($updates_xml)) {
    $name = (string)$update_xml['name'];
    $p['name'][] = $name;
    
    $repo_alias_xml = $repos_xml->xpath(sprintf('//repo[@alias="%s"]', (string)$update_xml->source['alias']));
    $p['repo'][] = (string)$repo_alias_xml[0]['name'];

    $p['edition'][] = (string)$update_xml['edition'];
    $p['edition_current'][] = exec(sprintf(CMD_RPM_GET_VERSION, $name));
    $p['arch'][] = (string)$update_xml['arch'];

    $p['install'][] = generate_confirmation_url('package', $name);
  }

  // in order to print an ascii-table, max width is needed for each column
  foreach($p as $key => $values) { $width[$key] = max(array_map('strlen', $values)); }

  // text:
  for ($i=0; $i < count($p['name']); $i++) {
    if ($i==1)
      $output_text .= sprintf("%'--${width['repo']}s-+-%'--${width['name']}s-+-%'--${width['edition_current']}s-+-%'--${width['edition']}s-+-%'--${width['arch']}s-+-%'--${width['install']}s\n",
        '', '', '', '', '', '');
    else 
      $output_text .= sprintf("%-${width['repo']}s | %-${width['name']}s | %-${width['edition_current']}s | %-${width['edition']}s | %-${width['arch']}s | %-${width['install']}s\n",
        $p['repo'][$i], $p['name'][$i], $p['edition_current'][$i], $p['edition'][$i], $p['arch'][$i], $p['install'][$i]);
  }
  $output_text .= sprintf("\nInstall all packages: %s", generate_confirmation_url('all-packages'));

  // html:
  if (! is_null($output_html)) {
    $output_html .= "<table>\n";
    for ($i=0; $i < count($p['name']); $i++) {
      if ($i==1)
        continue;

      if ($i==0)
        $output_html .= sprintf("<tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr>\n",
          $p['repo'][$i], $p['name'][$i], $p['edition_current'][$i], $p['edition'][$i], $p['arch'][$i], $p['install'][$i]);
      else
        $output_html .= sprintf("<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>\n",
          $p['repo'][$i], $p['name'][$i], $p['edition_current'][$i], $p['edition'][$i], $p['arch'][$i], sprintf('<a href="%s">install</a>', $p['install'][$i]));
    }
    $output_html .= sprintf("</table>\n<p>All packages: <a href=\"%s\">installation</a></p>\n", generate_confirmation_url('all-packages'));
  }
}

generate_and_set_updateID();

exec(CMD_ZYPPER_LR, $repos, $exit);
$repos_xml = new SimpleXMLElement(implode("\n", $repos));

$message_patches['text'] = ''; $message_patches['html'] = '';
$message_packages['text'] = ''; $message_packages['html'] = '';

foreach ($UPDATE_TYPE as $type) {
  switch ($type) {
    case 'patches':
      mailbody_patches($message_patches['text'], $message_patches['html']); 
      break;
    case 'packages':
      mailbody_packages($message_packages['text'], $message_packages['html']); 
      break;
  }
}

$hostname = exec(CMD_HOSTNAME);

$vars['Hostname'] = $hostname;
$vars['WhatToConfirm'] = implode(' / ', $UPDATE_TYPE);
$vars['UpdatesText'] = trim("{$message_patches['text']}\n\n{$message_packages['text']}");
$vars['UpdatesHTML'] = "{$message_patches['html']}{$message_packages['html']}";

switch ($MAIL_STYLE) {
  case 'text':
    $header = '';
    $message = replace_variables($MAIL_TEMPLATE_TEXT, $vars);
    break;
  case 'html':
    $message_text = replace_variables($MAIL_TEMPLATE_TEXT, $vars);
    $message_html = replace_variables($MAIL_TEMPLATE_HTML, $vars);

//    $message_text = base64_encode($message_text);
//    $message_html = base64_encode($message_html);

    $header = 'Content-Type: multipart/alternative; boundary="frontier"';
    $message = <<<EOT
--frontier
Content-Type: text/plain; charset="utf-8"
Content-Transfer-Encoding: 8bit

{$message_text}

--frontier
Content-Type: text/html; charset="utf-8"
Content-Transfer-Encoding: 8bit

{$message_html}

--frontier--
EOT;
    break;
}

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

mail($MAIL_TO, sprintf($MAIL_SUBJECT_REQUEST, $hostname), $message, $header);
?>
