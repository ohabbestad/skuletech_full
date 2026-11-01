<?php
declare(strict_types=1);
// Offline only. Accept credentials over stdin; never through command-line arguments.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/../api/domain.php';
try {
    $args=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $out=$args['output']; if(!is_dir($out)) throw new RuntimeException('Opprett utdatamappa først.');
    $password=$args['password'];
    if(!is_string($password)||strlen($password)<14||strlen($password)>72) throw new RuntimeException('Vel eit passord på 14–72 byte.');
    $doc=isset($args['seed'])?plan_validate(json_decode(file_get_contents($args['seed']),true,512,JSON_THROW_ON_ERROR)):plan_empty();
    $config=[];foreach(['db_host','db_name','db_user','db_pass'] as $key){if(!is_string($args[$key]??null)||$args[$key]==='')throw new RuntimeException('Manglar databasefelt.');$config[$key]=$args[$key];}
    $config['db_port']=3306;
    $sql=file_get_contents(__DIR__.'/../sql/schema.sql');
    $hash=password_hash($password,PASSWORD_DEFAULT);
    // Hex literals avoid SQL-mode-dependent quote/backslash escaping and preserve UTF-8.
    $sql.="\nINSERT INTO plan_owner(id,password_hash) VALUES(1,0x".bin2hex($hash).");\n";
    $sql.="INSERT INTO plan_state(id,revision,document) VALUES(1,0,CONVERT(0x".bin2hex(json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))." USING utf8mb4));\n";
    file_put_contents($out.'/database.sql',$sql);
    file_put_contents($out.'/config.local.php',"<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n");
    echo "Oppsettsfiler er laga. Oppbevar dei privat. Databasepassord er ikkje skrive til terminalen.\n";
}catch(Throwable $e){fwrite(STDERR,"Oppsettet vart stoppa: ".$e->getMessage()."\n");exit(1);}
