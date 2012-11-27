<?php
// can be a subset of array('patches', 'packages')
$UPDATE_TYPE = array('patches', 'packages');

// The generate URL has the following form:
// $URL_PREFIX . <uniqueID> . "/" . task
$URL_PREFIX = 'https://example.com/i/';

// the task itself, e.g. "patch/openSUSE-2012-794", which means
// to install patch "openSUSE-2012-794", is AES encrypted using the
// key $TASK_PSK
$TASK_PSK = 'secret';

$MAIL_FROM = 'root@example.com';
$MAIL_TO = 'admin@example.com';

// %s will be replaced by the hostname
$MAIL_SUBJECT_REQUEST = "[zypper-update-request] New updates available for %s";
$MAIL_SUBJECT_APPLY = "[zypper-update-request] Installation log of updates for %s";

// "text": a text mail is sent.
// "html": an html mail, which includes the text mail as an alternative,
//         is sent.
$MAIL_STYLE = 'html';

// e.g.: /var/log/apache2/access_log
$HTTPD_LOG = 'access_log';

// e.g.: /var/run/zypper-update-requestor/update.id
$RUNFILE = 'update.id';

// can be "x509" or "none"
$MAIL_ENCRYPTION = 'none';

// as PEM:
$CERTIFICATE_x509 = <<<EOT
-----BEGIN CERTIFICATE-----
ENTER HERE YOUR PUBLIC KEY
-----END CERTIFICATE-----
EOT;


/**
* The following keywords are substituted in the templates:
* {WhatToConfirm} string representation of $UPDATE_TYPE
* {Hostname}      the hosts FQDN (Fully Qualified Domain Name)
* {UpdatesText}   ascii formated table of the updates
* {UpdatesHTML}   html formated table of the updates
*/

$MAIL_TEMPLATE_TEXT = <<<EOT
New updates available for host {Hostname}.
Please confirm the {WhatToConfirm} you want to install.

{UpdatesText}
EOT;

$MAIL_TEMPLATE_HTML = <<<EOT
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//DE" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
  <title>New updates available for host {Hostname}</title>
  <meta http-equiv="content-type" content="text/html;charset=utf-8">
  <style type="text/css">
    table { border-collapse: collapse; }
    th { border-bottom: 2px solid gray; padding: 0 0.5em; text-align: left; }
    th+th { border-left: 1px solid gray; }
    td { border-top: 1px solid gray; padding: 0 0.5em; text-align: left; vertical-align: top; }
    td+td { border-left: 1px solid gray; }
    .max-width { max-width: 15em; }
  </style>
</head>
<body>
<h1>New updates available for host {Hostname}</h1>
<p>Please confirm the {WhatToConfirm} you want to install.</p>
{UpdatesHTML}
</body>
</html>
EOT;
?>
