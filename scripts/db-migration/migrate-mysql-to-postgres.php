<?php
declare(strict_types=1);
function abortMigration(string $message): never { fwrite(STDERR, "ERROR: {$message}\n"); exit(1); }
function pgIdent(string $value): string { return '"' . str_replace('"', '""', $value) . '"'; }
function myIdent(string $value): string { return chr(96) . str_replace(chr(96), chr(96).chr(96), $value) . chr(96); }
$mysql=null; $pg=null;
try {
 $mysql=new PDO(getenv('MYSQL_DSN')?:'mysql:host=mysql-source;port=3306;dbname=sederg_pay;charset=utf8mb4',getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>false]);
 $pg=new PDO(getenv('PG_DSN')?:'pgsql:host=postgres;port=5432;dbname=sederg_pay',getenv('PG_USER')?:'sederg_app',getenv('PG_PASSWORD')?:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $found=$mysql->query('SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'')->fetchAll(PDO::FETCH_NUM);
 $tables=array_values(array_filter(array_map(fn($r)=>(string)$r[0],$found),fn($t)=>$t!=='yii_migration')); sort($tables);
 if(!$tables) abortMigration('No MySQL source tables found.');
 $target=$pg->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
 $targetSet=array_fill_keys($target,true);
 $missing=array_values(array_filter($tables,fn($t)=>!isset($targetSet[$t])));
 if($missing) abortMigration('Missing target tables; nothing copied: '.implode(', ',$missing));
 $pg->beginTransaction(); $pg->exec('SET LOCAL session_replication_role = replica'); $total=0; $report=[];
 foreach($tables as $table) {
  $pt=pgIdent($table); $mt=myIdent($table);
  $already=(int)$pg->query("SELECT COUNT(*) FROM {$pt}")->fetchColumn();
  if($already!==0) abortMigration("Target {$table} already contains {$already} rows; refusing to overwrite.");
  $src=$mysql->query("SHOW COLUMNS FROM {$mt}")->fetchAll();
  $srcNames=array_fill_keys(array_map(fn($c)=>(string)$c['Field'],$src),true);
  $q=$pg->prepare("SELECT column_name,data_type,is_nullable,column_default,is_identity,is_generated FROM information_schema.columns WHERE table_schema='public' AND table_name=:t ORDER BY ordinal_position");
  $q->execute([':t'=>$table]); $dst=$q->fetchAll(); $map=[];
  foreach($dst as $c) $map[$c['column_name']]=$c;
  $copy=[];
  foreach($src as $c) { $n=(string)$c['Field']; if(isset($map[$n])&&$map[$n]['is_generated']!=='ALWAYS') $copy[]=$n; }
  if(!$copy) abortMigration("No common columns in {$table}.");
  foreach($dst as $c) {
   $required=$c['is_nullable']==='NO'&&$c['column_default']===null&&$c['is_identity']!=='YES'&&$c['is_generated']==='NEVER';
   if($required&&!isset($srcNames[$c['column_name']])) abortMigration("Required target column {$table}.{$c['column_name']} has no source/default.");
  }
  $sel=implode(', ',array_map('myIdent',$copy)); $cols=implode(', ',array_map('pgIdent',$copy)); $params=implode(', ',array_map(fn($n)=>':'.$n,$copy));
  $rows=$mysql->query("SELECT {$sel} FROM {$mt}"); $insert=$pg->prepare("INSERT INTO {$pt} ({$cols}) VALUES ({$params})"); $count=0;
  while($row=$rows->fetch(PDO::FETCH_ASSOC)) {
   $values=[];
   foreach($copy as $n) {
    $v=$row[$n]; $type=$map[$n]['data_type'];
    if(is_string($v)&&in_array($v,['0000-00-00','0000-00-00 00:00:00','0000-00-00 00:00:00.000000'],true)) $v=null;
    if($type==='boolean'&&$v!==null) $v=in_array(strtolower((string)$v),['1','true','yes'],true);
    $values[':'.$n]=$v;
   }
   $insert->execute($values); $count++;
  }
  $targetCount=(int)$pg->query("SELECT COUNT(*) FROM {$pt}")->fetchColumn();
  if($targetCount!==$count) abortMigration("Row count mismatch for {$table}: source={$count}, target={$targetCount}.");
  $report[$table]=$count; $total+=$count;
 }
 $ids=$pg->query("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='public' AND (is_identity='YES' OR column_default LIKE 'nextval(%')")->fetchAll();
 foreach($ids as $id) {
  $table=(string)$id['table_name']; $column=(string)$id['column_name']; $seq=$pg->prepare('SELECT pg_get_serial_sequence(:t,:c)');
  $seq->execute([':t'=>$table,':c'=>$column]); $sequence=$seq->fetchColumn();
  if($sequence) { $t=pgIdent($table); $c=pgIdent($column); $reset=$pg->prepare("SELECT setval(:seq,GREATEST(COALESCE((SELECT MAX({$c}) FROM {$t}),1),1),EXISTS(SELECT 1 FROM {$t}))"); $reset->execute([':seq'=>$sequence]); }
 }
 $pg->commit(); echo "DATA TRANSFER COMPLETED\nTables: ".count($report)."\nRows: {$total}\n"; foreach($report as $t=>$n) echo "{$t}: {$n}\n"; echo "MySQL source unchanged; no target table truncated or dropped.\n";
} catch(Throwable $e) { if($pg instanceof PDO&&$pg->inTransaction()) $pg->rollBack(); abortMigration($e->getMessage()); }
