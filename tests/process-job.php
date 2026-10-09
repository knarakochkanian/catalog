<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/vendor/autoload.php';
if (getenv('E2E_LOCAL_WORKER')!=='1' || !isset($argv[1]) || !preg_match('/^[a-f0-9]{32}$/D',$argv[1])) { throw new RuntimeException('This helper requires explicit isolated test mode and a job ID.'); }
$app=new App\Bootstrap();$app->processor->process($argv[1]);
$job=$app->jobs->get($argv[1]);
echo json_encode(['status'=>$job['status'],'total'=>$job['total'],'created'=>$job['created'],'updated'=>$job['updated'],'skipped'=>$job['skipped'],'errors'=>$job['errors']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
