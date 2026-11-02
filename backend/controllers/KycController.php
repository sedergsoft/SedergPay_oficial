<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Auth\UserAuthenticator;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UploadedFile;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\UnprocessableEntityHttpException;
use yii\web\NotFoundHttpException;

final class KycController extends Controller
{
    public $enableCsrfValidation = false;
    public function behaviors(): array
    {
        return ['contentNegotiator' => ['class' => ContentNegotiator::class, 'formats' => ['application/json' => Response::FORMAT_JSON]]];
    }
    private function userId(): int { return (new UserAuthenticator(Yii::$app->db))->userId(); }
    private function uuid(): string { $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4)); }

    public function actionView(): array
    {
        $id=$this->userId();
        $case=Yii::$app->db->createCommand('SELECT public_id,status,submitted_at,reviewed_at,review_reason,created_at,updated_at FROM {{%kyc_cases}} WHERE user_id=:id LIMIT 1',[':id'=>$id])->queryOne();
        if ($case===false) return ['status'=>'not_started','documents'=>[]];
        $case['documents']=Yii::$app->db->createCommand('SELECT public_id,document_type,original_name,mime_type,file_size,created_at FROM {{%kyc_documents}} WHERE kyc_case_id=(SELECT id FROM {{%kyc_cases}} WHERE user_id=:id) ORDER BY created_at',[':id'=>$id])->queryAll();
        return $case;
    }

    public function actionUploadDocument(): array
    {
        $userId=$this->userId();
        $case=Yii::$app->db->createCommand('SELECT id,status FROM {{%kyc_cases}} WHERE user_id=:id LIMIT 1',[':id'=>$userId])->queryOne();
        if ($case!==false && in_array($case['status'],['approved','pending_review'],true)) throw new ForbiddenHttpException('KYC documents cannot be changed while approved or awaiting review');
        $type=(string)Yii::$app->request->post('document_type','identity');
        if (!in_array($type,['identity','address','selfie'],true)) throw new UnprocessableEntityHttpException('Unsupported document type');
        $file=UploadedFile::getInstanceByName('file');
        if ($file===null || $file->error!==UPLOAD_ERR_OK || $file->size>5*1024*1024) throw new UnprocessableEntityHttpException('Upload a document up to 5 MB');
        $finfo=new \finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($file->tempName);
        $extensions=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];
        if (!isset($extensions[$mime])) throw new UnprocessableEntityHttpException('Only PDF, JPG and PNG documents are accepted');
        $encryptionKey=(string)(getenv('SEDERG_PII_KEY') ?: getenv('SEDERG_COOKIE_KEY'));
        if (strlen($encryptionKey)<16) throw new \yii\web\ServerErrorHttpException('KYC encryption key is not configured');
        $originalContent=file_get_contents($file->tempName);
        if ($originalContent===false) throw new \yii\web\ServerErrorHttpException('Cannot read uploaded document');
        $encryptedContent=Yii::$app->security->encryptByKey($originalContent,$encryptionKey);
        $root=rtrim(trim((string)getenv('SEDERG_PRIVATE_STORAGE_PATH')), DIRECTORY_SEPARATOR);
        if ($root==='' || !is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) throw new \yii\web\ServerErrorHttpException('Private document storage is not configured');
        $key=bin2hex(random_bytes(24)).'.'.$extensions[$mime];
        $folder=$root.'/kyc/'.$userId;
        if (!is_dir($folder) && !mkdir($folder,0700,true) && !is_dir($folder)) throw new \yii\web\ServerErrorHttpException('Cannot create private document directory');
        if (file_put_contents($folder.'/'.$key,$encryptedContent,LOCK_EX)===false) throw new \yii\web\ServerErrorHttpException('Cannot store KYC document');
        @chmod($folder.'/'.$key,0600);
        $tx=Yii::$app->db->beginTransaction();
        try {
            if ($case===false) {
                Yii::$app->db->createCommand()->insert('{{%kyc_cases}}',['public_id'=>$this->uuid(),'user_id'=>$userId,'status'=>'draft'])->execute();
                $caseId=(int)Yii::$app->db->getLastInsertID();
            } else { $caseId=(int)$case['id']; }
            Yii::$app->db->createCommand()->insert('{{%kyc_documents}}',[
                'public_id'=>$this->uuid(),'kyc_case_id'=>$caseId,'document_type'=>$type,
                'storage_key'=>'kyc/'.$userId.'/'.$key,'original_name'=>mb_substr(basename($file->name),0,190),
                'mime_type'=>$mime,'file_size'=>$file->size,'sha256'=>hash('sha256',$originalContent),
            ])->execute();
            Yii::$app->db->createCommand()->update('{{%kyc_cases}}',['status'=>'draft','review_reason'=>null,'reviewed_at'=>null,'reviewed_by_user_id'=>null],['id'=>$caseId])->execute();
            Yii::$app->db->createCommand()->insert('{{%audit_logs}}',['actor_user_id'=>$userId,'action'=>'kyc.document.uploaded','entity_type'=>'kyc_case','entity_id'=>(string)$caseId,'ip_address'=>Yii::$app->request->userIP,'user_agent'=>substr((string)Yii::$app->request->userAgent,0,500),'after_data'=>new \yii\db\JsonExpression(['document_type'=>$type,'mime_type'=>$mime,'file_size'=>$file->size])])->execute();
            $tx->commit();
        } catch (\Throwable $e) { if ($tx->getIsActive()) $tx->rollBack(); @unlink($folder.'/'.$key); throw $e; }
        return ['uploaded'=>true,'document_type'=>$type,'file_name'=>basename($file->name)];
    }

    public function actionSubmit(): array
    {
        $userId=$this->userId();
        $case=Yii::$app->db->createCommand('SELECT id,status FROM {{%kyc_cases}} WHERE user_id=:id LIMIT 1',[':id'=>$userId])->queryOne();
        if ($case===false) throw new UnprocessableEntityHttpException('Upload the required documents first');
        if (in_array($case['status'],['approved','pending_review'],true)) throw new ForbiddenHttpException('This KYC case cannot be submitted in its current state');
        $profile=Yii::$app->db->createCommand('SELECT date_of_birth,address_line,city,residence_country_id FROM {{%user_profiles}} WHERE user_id=:id LIMIT 1',[':id'=>$userId])->queryOne();
        if ($profile===false || empty($profile['date_of_birth']) || empty($profile['address_line']) || empty($profile['city']) || empty($profile['residence_country_id'])) throw new UnprocessableEntityHttpException('Complete your date of birth and residential address in your profile before submitting KYC');
        $types=Yii::$app->db->createCommand('SELECT DISTINCT document_type FROM {{%kyc_documents}} WHERE kyc_case_id=:id',[':id'=>$case['id']])->queryColumn();
        if (!in_array('identity',$types,true) || !in_array('address',$types,true)) throw new UnprocessableEntityHttpException('Identity and proof-of-address documents are required');
        Yii::$app->db->createCommand()->update('{{%kyc_cases}}',['status'=>'pending_review','submitted_at'=>gmdate('Y-m-d H:i:s'),'review_reason'=>null],['id'=>$case['id']])->execute();
        Yii::$app->db->createCommand()->insert('{{%audit_logs}}',['actor_user_id'=>$userId,'action'=>'kyc.case.submitted','entity_type'=>'kyc_case','entity_id'=>(string)$case['id'],'ip_address'=>Yii::$app->request->userIP,'user_agent'=>substr((string)Yii::$app->request->userAgent,0,500)])->execute();
        return ['status'=>'pending_review'];
    }
}
