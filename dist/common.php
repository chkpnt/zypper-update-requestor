<?php
const CMD_ZYPPER_LU_PATCH   = 'zypper -q -x lu -t patch';
const CMD_ZYPPER_LU_PACKAGE = 'zypper -q -x lu -t package';
const CMD_ZYPPER_LR         = 'zypper -q -x lr';
const CMD_ZYPPER_UP_PATCH   = 'zypper -n -v --no-refresh up -t patch --dry-run %s';
const CMD_ZYPPER_UP_PACKAGE = 'zypper -n -v --no-refresh up -t package --dry-run %s';
const CMD_ZYPPER_PS         = 'zypper ps';
const CMD_RPM_GET_VERSION   = "rpm -q --qf '%%{VERSION}-%%{RELEASE}' %s";
const CMD_FGREP_LOG         = 'fgrep %s %s';
const CMD_HOSTNAME          = 'hostname -f';
const ZYPP_PID_FILE         = '/var/run/zypp.pid';
const ZYPPER_EXIT_ERR_ZYPP  = 4;


/**
* Taken from http://staticfloat.com/php-programmieren/aes-256-verschlusselung-in-php/
* Using AES-128.
*/
function mc_encrypt($encrypt, $mc_key) {
    $iv = mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_ECB),
                            MCRYPT_RAND);
    $passcrypt = trim(mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $mc_key,
                            trim($encrypt),
                            MCRYPT_MODE_ECB, $iv));
    $encode = base64_encode($passcrypt);
    return $encode;
}

/**
* Taken from http://staticfloat.com/php-programmieren/aes-256-verschlusselung-in-php/
* Using AES-128.
*/
function mc_decrypt($decrypt, $mc_key) {
    $decoded = base64_decode($decrypt);
    $iv = mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_ECB),
                            MCRYPT_RAND);
    $decrypted = trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $mc_key, trim($decoded),
                            MCRYPT_MODE_ECB, $iv));
    return $decrypted;
}

/**
 * Taken from syscp:
 * https://github.com/flol/SysCP/blob/master/syscp/lib/functions/phphelpers/function.replace_variables.php
 *
 * Replaces all occurences of variables defined in the second argument
 * in the first argument with their values.
 *
 * @param string The string that should be searched for variables
 * @param array The array containing the variables with their values
 * @return string The submitted string with the variables replaced.
 * @author Michael Duergner
 */
function replace_variables($text,$vars) {
    $pattern = "/\{([a-zA-Z0-9\-_]+)\}/";
    // --- martin @ 08.08.2005 -------------------------------------------------------
    // fixing usage of uninitialised variable
    $matches = array();
    // -------------------------------------------------------------------------------
    if(count($vars) > 0 && preg_match_all($pattern,$text,$matches)) {
        for($i = 0;$i < count($matches[1]);$i++) {
            $current = $matches[1][$i];
            if (isset ($vars[$current]) ) {
                $var = $vars[$current];
                $text = str_replace("{" . $current . "}",$var,$text);
            }
        }
    }
    $text = str_replace ( '\n', "\n" , $text ) ;
    return $text;
}
 
?>
