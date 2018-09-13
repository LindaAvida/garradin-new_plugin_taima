<?php

namespace Garradin;

use KD2\Security;
use KD2\Form;
use KD2\Translate;
use KD2\SMTP;

class Utils
{
    static protected $skriv = null;

    static private $french_date_names = [
        'January'=>'Janvier', 'February'=>'Février', 'March'=>'Mars', 'April'=>'Avril', 'May'=>'Mai',
        'June'=>'Juin', 'July'=>'Juillet', 'August'=>'Août', 'September'=>'Septembre', 'October'=>'Octobre',
        'November'=>'Novembre', 'December'=>'Décembre', 'Monday'=>'Lundi', 'Tuesday'=>'Mardi', 'Wednesday'=>'Mercredi',
        'Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi','Sunday'=>'Dimanche',
        'Feb'=>'Fév','Apr'=>'Avr','May'=>'Mai','Jun'=>'Juin', 'Jul'=>'Juil','Aug'=>'Aout','Dec'=>'Déc',
        'Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mer','Thu'=>'Jeu','Fri'=>'Ven','Sat'=>'Sam','Sun'=>'Dim'];

    static public function strftime_fr($format=null, $ts=null)
    {
        if (is_null($format))
        {
            $format = '%d/%m/%Y à %H:%M';
        }

        $date = strftime($format, $ts);
        $date = strtr($date, self::$french_date_names);
        $date = strtolower($date);
        return $date;
    }

    static public function date_fr($format=null, $ts=null)
    {
        if (is_null($format))
        {
            $format = 'd/m/Y à H:i';
        }

        $date = date($format, $ts);
        $date = strtr($date, self::$french_date_names);
        $date = strtolower($date);
        return $date;
    }

    static public function sqliteDateToFrench($d, $short = false)
    {
        if (strlen($d) == 10 || $short)
        {
            $d = substr($d, 0, 10);
            $f = 'Y-m-d';
            $f2 = 'd/m/Y';
        }
        elseif (strlen($d) == 16)
        {
            $f = 'Y-m-d H:i';
            $f2 = 'd/m/Y H:i';
        }
        else
        {
            $f = 'Y-m-d H:i:s';
            $f2 = 'd/m/Y H:i';
        }
        
        if ($dt = \DateTime::createFromFormat($f, $d))
            return $dt->format($f2);
        else
            return $d;
    }

    static public function makeTimestampFromForm($d)
    {
        return mktime($d['h'], $d['min'], 0, $d['m'], $d['d'], $d['y']);
    }

    static public function modifyDate($str, $change, $as_timestamp = false)
    {
        $date = \DateTime::createFromFormat('Y-m-d', $str);
        $date->modify($change);
        return ($as_timestamp ? $date->getTimestamp() : $date->format('Y-m-d'));
    }

    static public function checkDate($str)
    {
        if (!preg_match('!^(\d{4})-(\d{2})-(\d{2})$!', $str, $match))
            return false;

        if (!checkdate($match[2], $match[3], $match[1]))
            return false;

        return true;
    }

    static public function checkDateTime($str)
    {
        if (!preg_match('!^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})!', $str, $match))
            return false;

        if (!self::checkDate($match[1]))
            return false;

        if ((int) $match[2] < 0 || (int) $match[2] > 23)
            return false;

        if ((int) $match[3] < 0 || (int) $match[3] > 59)
            return false;
        
        if (isset($match[4]) && ((int) $match[4] < 0 || (int) $match[4] > 59))
            return false;

        return true;
    }

    static public function getRequestURI()
    {
        if (!empty($_SERVER['REQUEST_URI']))
            return $_SERVER['REQUEST_URI'];
        else
            return false;
    }

    static public function getSelfURL($qs = true)
    {
        $uri = self::getRequestUri();

        if (strpos($uri, WWW_URI) === 0)
        {
            $uri = substr($uri, strlen(WWW_URI));
        }

        if ($qs !== true && (strpos($uri, '?') !== false))
        {
            $uri = substr($uri, 0, strpos($uri, '?'));
        }

        if (is_array($qs))
        {
            $uri .= '?' . http_build_query($qs);
        }

        return str_replace('/admin', '', ADMIN_URL) . $uri;
    }

    public static function redirect($destination=false, $exit=true)
    {
        if (empty($destination) || !preg_match('/^https?:\/\//', $destination))
        {
            if (empty($destination))
                $destination = WWW_URL;
            else
                $destination = WWW_URL . preg_replace('/^\//', '', $destination);
        }

        if (headers_sent())
        {
            echo
              '<html>'.
              ' <head>' .
              '  <script type="text/javascript">' .
              '    document.location = "' . htmlspecialchars($destination, ENT_QUOTES, 'UTF-8', false) . '";' .
              '  </script>' .
              ' </head>'.
              ' <body>'.
              '   <div>'.
              '     <a href="' . htmlspecialchars($destination, ENT_QUOTES, 'UTF-8', false) . '">Cliquez ici pour continuer...</a>'.
              '   </div>'.
              ' </body>'.
              '</html>';

            if ($exit)
              exit();

            return true;
        }

        header("Location: " . $destination);

        if ($exit)
          exit();
    }

    static public function getIP()
    {
        if (!empty($_SERVER['REMOTE_ADDR']))
            return $_SERVER['REMOTE_ADDR'];
        return '';
    }

    static public function getCountryList()
    {
        return Translate::getCountriesList('fr');
    }

    static public function getCountryName($code)
    {
        $list = self::getCountryList();

        if (!isset($list[$code]))
            return false;

        return $list[$code];
    }

    /**
     * Génération pagination à partir de la page courante ($current),
     * du nombre d'items total ($total), et du nombre d'items par page ($bypage).
     * $listLength représente la longueur d'items de la pagination à génerer
     *
     * @param int $current
     * @param int $total
     * @param int $bypage
     * @param int $listLength
     * @param bool $showLast Toggle l'affichage du dernier élément de la pagination
     * @return array
     */
    public static function getGenericPagination($current, $total, $bypage, $listLength=11, $showLast = true)
    {
        if ($total <= $bypage)
            return false;

        $total = ceil($total / $bypage);

        if ($total < $current)
            return false;

        $length = ($listLength / 2);

        $begin = $current - ceil($length);
        if ($begin < 1)
        {
            $begin = 1;
        }

        $end = $begin + $listLength;
        if($end > $total)
        {
            $begin -= ($end - $total);
            $end = $total;
        }
        if ($begin < 1)
        {
            $begin = 1;
        }
        if($end==($total-1)) {
            $end = $total;
        }
        if($begin == 2) {
            $begin = 1;
        }
        $out = [];

        if ($current > 1) {
            $out[] = ['id' => $current - 1, 'label' =>  '« ' . 'Page précédente', 'class' => 'prev', 'accesskey' => 'a'];
        }

        if ($begin > 1) {
            $out[] = ['id' => 1, 'label' => '1 ...', 'class' => 'first'];
        }

        for ($i = $begin; $i <= $end; $i++)
        {
            $out[] = ['id' => $i, 'label' => $i, 'class' => ($i == $current) ? 'current' : ''];
        }

        if ($showLast && $end < $total) {
            $out[] = ['id' => $total, 'label' => '... ' . $total, 'class' => 'last'];
        }

        if ($current < $total) {
            $out[] = ['id' => $current + 1, 'label' => 'Page suivante' . ' »', 'class' => 'next', 'accesskey' => 'z'];
        }

        return $out;
    }

    static public function transliterateToAscii($str, $charset='UTF-8')
    {
        // Don't process empty strings
        if (!trim($str))
            return $str;

        // We only process non-ascii strings
        if (preg_match('!^[[:ascii:]]+$!', $str))
            return $str;

        $str = htmlentities($str, ENT_NOQUOTES, $charset);

        $str = preg_replace('#&([A-za-z])(?:acute|cedil|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $str);
        $str = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $str); // pour les ligatures e.g. '&oelig;'

        $str = preg_replace('#&[^;]+;#', '', $str); // supprime les autres caractères
        $str = preg_replace('![^[:ascii:]]+!', '', $str);

        return $str;
    }

    static public function htmlLinksOnUrls($str)
    {
        return preg_replace_callback('!(?<=\s|^)((?:(ftp|https?|file|ed2k|ircs?)://|(magnet|mailto|data|tel|fax|geo|sips?|xmpp):)([^\s<]+))!',
            function ($match) {
                $proto = $match[2] ?: $match[3];
                $text = ($proto == 'http' || $proto == 'mailto') ? $match[4] : $match[1];
                return '<a class="'.$proto.'" href="'.htmlspecialchars($match[1], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($text, ENT_QUOTES, 'UTF-8').'</a>';
            }, $str);
    }

    /**
     * Transforme un texte SkrivML en HTML
     * @param  string $str Texte SkrivML
     * @return string      Texte HTML
     */
    static public function SkrivToHTML($str)
    {
        if (!self::$skriv)
        {
            self::$skriv = new \KD2\SkrivLite;
            self::$skriv->registerExtension('fichier', ['\\Garradin\\Fichiers', 'SkrivFichier']);
            self::$skriv->registerExtension('image', ['\\Garradin\\Fichiers', 'SkrivImage']);

            // Enregistrer d'autres extensions éventuellement
            Plugin::fireSignal('skriv.init', ['skriv' => self::$skriv]);
        }

        $skriv =& self::$skriv;

        $str = preg_replace_callback('/(fichier|image):\/\/(\d+)/', function ($match) use ($skriv) {
            try {
                $file = new Fichiers((int)$match[2]);
            }
            catch (\InvalidArgumentException $e)
            {
                return $skriv->parseError('/!\ Lien fichier : ' . $e->getMessage());
            }

            return $file->getURL();
        }, $str);

        $str = self::$skriv->render($str);

        return $str;
    }

    /**
     * Transforme les tags de base SPIP en tags SkrivML
     * @param  string $str Texte d'entrée
     * @return string      Texte transformé
     */
    static public function SpipToSkriv($str)
    {
        $str = preg_replace('/(?<!\\\\)\{{3}(\V*)\}{3}/', '=== $1 ===', $str);
        $str = preg_replace('/(?<!\\\\)\{{2}(\V*)\}{2}/', '**$1**', $str);
        $str = preg_replace('/(?<!\\\\)\{(\V*)\}/', '\'\'$1\'\'', $str);
        $str = preg_replace('/(?<!\\\\)\[(.+?)->(.+?)\]/', '[[$1 | $2]]', $str);
        $str = preg_replace('/(?<!\[)\[([^\[\]]+?)\]/', '[[$1]]', $str);
        return $str;
    }

    /**
     * Transforme les tags HTML basiques en tags SkrivML
     * @param  string $str Texte d'entrée
     * @return string      Texte transformé
     */
    static public function HTMLToSkriv($str)
    {
        $str = preg_replace('/<h3>(\V*?)<\/h3>/', '=== $1 ===', $str);
        $str = preg_replace('/<b>(\V*)<\/b>/', '**$1**', $str);
        $str = preg_replace('/<strong>(\V*?)<\/strong>/', '**$1**', $str);
        $str = preg_replace('/<i>(\V*?)<\/i>/', '\'\'$1\'\'', $str);
        $str = preg_replace('/<em>(\V*?)<\/em>/', '\'\'$1\'\'', $str);
        $str = preg_replace('/<li>(\V*?)<\/li>/', '* $1', $str);
        $str = preg_replace('/<ul>|<\/ul>/', '', $str);
        $str = preg_replace('/<a href="([^"]*?)">(\V*?)<\/a>/', '[[$2 | $1]]', $str);
        return $str;
    }

    static public function clearCaches($path = false)
    {
        if (!$path)
        {
            self::clearCaches('compiled');
            self::clearCaches('static');
            return true;
        }

        $path = CACHE_ROOT . '/' . $path;
        $dir = dir($path);

        while ($file = $dir->read())
        {
            if ($file[0] != '.')
            {
                self::safe_unlink($path . DIRECTORY_SEPARATOR . $file);
            }
        }

        $dir->close();
        return true;
    }

    static public function safe_unlink($path)
    {
        if (!@unlink($path))
        {
            return true;
        }
        
        if (!file_exists($path))
        {
            return true;
        }

        throw new \RuntimeException(sprintf('Impossible de supprimer le fichier %s: %s', $path, error_get_last()));

        return true;
    }

    static public function safe_mkdir($path, $mode = 0777, $recursive = false)
    {
        return @mkdir($path, $mode, $recursive) || is_dir($path);
    }

    static public function suggestPassword()
    {
        return Security::getRandomPassphrase(ROOT . '/include/data/dictionary.fr');
    }

    static public function checkIBAN($value)
    {
        // Enlever les caractères indésirables (espaces, tirets),
        $value = preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
        
        // Supprimer les 4 premiers caractères et les replacer à la fin du compte
        $value = substr($value, 4) . substr($value, 0, 4);

        // Remplacer les lettres par des chiffres au moyen d'une table de conversion (A=10, B=11, C=12 etc.)
        $value = str_replace(range('A', 'Z'), range(10, 35), $value);

        // Diviser le nombre ainsi obtenu par 97
        // Si le reste n'est pas égal à 1 l'IBAN est incorrect : Modulo de 97 égal à 1.
        return (self::bcmod($value, 97) == 1);
    }

    /** 
     * my_bcmod - get modulus (substitute for bcmod) 
     * string my_bcmod ( string left_operand, int modulus ) 
     * left_operand can be really big, but be carefull with modulus :( 
     * by Andrius Baranauskas and Laurynas Butkus :) Vilnius, Lithuania 
     * @link https://php.net/manual/fr/function.bcmod.php#38474
     */ 
    static public function bcmod($x, $y)
    {
        if (function_exists('\bcmod'))
        {
            return \bcmod($x, $y);
        }

        // how many numbers to take at once? carefull not to exceed (int)
        $take = 5;
        $mod = '';

        do
        {
            $a = (int)$mod.substr( $x, 0, $take );
            $x = substr( $x, $take );
            $mod = $a % $y;
        } 
        while (strlen($x));

        return (int)$mod;
    }
    
    static public function checkBIC($bic)
    {
        return preg_match('!^[A-Z]{4}[A-Z]{2}[1-9A-Z]{2}(?:[A-Z\d]{3})?$!', $bic);
    }

    static public function normalizePhoneNumber($n)
    {
        $n = preg_replace('!(\+\d+)\(0\)!', '\\1', $n);
        $n = preg_replace('![^\d\+]!', '', $n);
        return $n;
    }

    static public function write_ini_string($in)
    {
        $out = '';
        $get_ini_line = function ($key, $value) use (&$get_ini_line)
        {
            if (is_bool($value))
            {
                return $key . ' = ' . ($value ? 'true' : 'false');
            }
            elseif (is_numeric($value))
            {
                return $key . ' = ' . $value;
            }
            elseif (is_array($value) || is_object($value))
            {
                $out = '';
                $value = (array) $value;
                foreach ($value as $row)
                {
                    $out .= $get_ini_line($key . '[]', $row) . "\n";
                }

                return substr($out, 0, -1);
            }
            else
            {
                return $key . ' = "' . str_replace('"', '\\"', $value) . '"';
            }
        };

        foreach ($in as $key=>$value)
        {
            if ((is_array($value) || is_object($value)) && is_string($key))
            {
                $out .= '[' . $key . "]\n";

                foreach ($value as $row_key=>$row_value)
                {
                    $out .= $get_ini_line($row_key, $row_value) . "\n";
                }

                $out .= "\n";
            }
            else
            {
                $out .= $get_ini_line($key, $value) . "\n";
            }
        }

        return $out;
    }

    static public function getMaxUploadSize()
    {
        $limits = [
            self::return_bytes(ini_get('upload_max_filesize')),
            self::return_bytes(ini_get('post_max_size')),
            self::return_bytes(ini_get('memory_limit'))
        ];

        return min(array_filter($limits));
    }


    static public function return_bytes($size_str)
    {
        if ($size_str == '-1')
        {
            return false;
        }

        switch (substr($size_str, -1))
        {
            case 'G': case 'g': return (int)$size_str * pow(1024, 3);
            case 'M': case 'm': return (int)$size_str * pow(1024, 2);
            case 'K': case 'k': return (int)$size_str * 1024;
            default: return $size_str;
        }
    }

    static public function format_bytes($size) {
        if ($size > (1024 * 1024))
            return str_replace('.', ',', round($size / 1024 / 1024, 2)) . ' Mo';
        elseif ($size > 1024)
            return str_replace('.', ',', round($size / 1024, 2)) . ' Ko';
        else
            return $size . ' o';
    }

    static public function deleteRecursive($path)
    {
        if (!file_exists($path))
            return false;

        $dir = dir($path);
        if (!$dir) return false;

        while ($file = $dir->read())
        {
            if ($file == '.' || $file == '..')
                continue;

            if (is_dir($path . '/' . $file))
            {
                if (!self::deleteRecursive($path . '/' . $file))
                    return false;
            }
            else
            {
                utils::safe_unlink($path . '/' . $file);
            }
        }

        $dir->close();
        rmdir($path);

        return true;
    }

    static public function plugin_url($params = [])
    {
        if (isset($params['id']))
        {
            $url = ADMIN_URL . 'plugin/' . $params['id'] . '/';
        }
        else
        {
            $url = PLUGIN_URL;
        }

        if (!empty($params['file']))
            $url .= $params['file'];

        if (!empty($params['query']))
        {
            $url .= '?';
            
            if (!(is_numeric($params['query']) && (int)$params['query'] === 1) && $params['query'] !== true)
                $url .= $params['query'];
        }

        return $url;
    }

    static public function find_csv_delim(&$fp)
    {
        $line = '';

        while ($line === '' && !feof($fp))
        {
            $line = trim(fgets($fp, 4096));
        }
        
        // Delete the columns content
        $line = preg_replace('/".*?"/', '', $line);

        $delims = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t"=> substr_count($line, "\t")
        ];

        arsort($delims);
        reset($delims);

        rewind($fp);

        return key($delims);
    }

    static public function skip_bom(&$fp)
    {
        // Skip BOM
        if (fgets($fp, 4) !== chr(0xEF) . chr(0xBB) . chr(0xBF))
        {
            fseek($fp, 0);
        }
    }

    static public function row_to_csv($row)
    {
        $row = (array) $row;

        array_walk($row, function (&$field) {
            $field = strtr($field, ['"' => '""', "\r\n" => "\n"]);
        });

        return sprintf("\"%s\"\r\n", implode('","', $row));
    }

    static public function sendEmail($recipient, $subject, $content, $id_membre = null, $pgp_key = null)
    {
        // Ne pas envoyer de mail à des adresses invalides
        if (!SMTP::checkEmailIsValid($recipient, false))
        {
            throw new UserException('Adresse email invalide: ' . $recipient);
        }

        $config = Config::getInstance();
        $subject = sprintf('[%s] %s', $config->get('nom_asso'), $subject);

        // Tentative d'envoi du message en utilisant un plugin
        $email_sent_via_plugin = Plugin::fireSignal('email.envoi', compact('recipient', 'subject', 'content', 'id_membre', 'pgp_key'));

        if (!$email_sent_via_plugin)
        {
            // L'envoi d'email n'a pas été effectué par un plugin, utilisons l'envoi interne
            // via mail() ou SMTP donc
            return self::mail($recipient, $subject, $content, $id_membre, $pgp_key);
        }

        return true;
    }

    static public function mail($to, $subject, $content, $id_membre, $pgp_key)
    {
        $headers = [];
        $config = Config::getInstance();

        $content = wordwrap($content);
        $content = trim($content);

        $content .= sprintf("\n\n-- \n%s\n%s\n\n", $config->get('nom_asso'), $config->get('site_asso'));
        $content .= "Vous recevez ce message car vous êtes inscrit comme membre de\nl'association.\n";
        $content .= "Pour ne plus recevoir de message de notre part merci de nous contacter :\n" . $config->get('email_asso');

        $content = preg_replace("#(?<!\r)\n#si", "\r\n", $content);

        if ($pgp_key)
        {
            $content = Security::encryptWithPublicKey($pgp_key, $content);
        }

        $headers['From'] = sprintf('"%s" <%s>', sprintf('=?UTF-8?B?%s?=', base64_encode($config->get('nom_asso'))), $config->get('email_asso'));
        $headers['Return-Path'] = $config->get('email_asso');

        $headers['MIME-Version'] = '1.0';
        $headers['Content-type'] = 'text/plain; charset=UTF-8';

        $hash = sha1(uniqid() . var_export([$headers, $to, $subject, $content], true));
        $headers['Message-ID'] = sprintf('%s@%s', $hash, isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : gethostname());

        if (SMTP_HOST)
        {
            $const = '\KD2\SMTP::' . strtoupper(SMTP_SECURITY);
            
            if (!defined($const))
            {
                throw new \LogicException('Configuration: SMTP_SECURITY n\'a pas une valeur reconnue. Valeurs acceptées: STARTTLS, TLS, SSL, NONE.');
            }

            $secure = constant($const);

            $smtp = new SMTP(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD, $secure);
            return $smtp->send($to, $subject, $content, $headers);
        }
        else
        {
            // Encodage du sujet
            $subject = sprintf('=?UTF-8?B?%s?=', base64_encode($subject));
            $raw_headers = '';

            // Sérialisation des entêtes
            foreach ($headers as $name=>$value)
            {
                $raw_headers .= sprintf("%s: %s\r\n", $name, $value);
            }

            return \mail($to, $subject, $content, $raw_headers);
        }
    }
}
