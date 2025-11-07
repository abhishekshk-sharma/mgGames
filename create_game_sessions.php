<?php
require_once "config.php";

session_start();

$error = 1;

$info = isset($_POST['info'])?$_POST['info']:null;

if($info !== null){
    $Gameid = isset($_POST['id'])?intval($_POST['id']): null;

    if($Gameid !== null){
        $_SESSION["Game_session_id"] = $Gameid;
        $error = 0;
    }
    else{
        $error = 1;
    }
}
else{
    $error = 1;
}

echo $error;

?>