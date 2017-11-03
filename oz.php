<?

/* ---------------------------------------------------------------------------- */
MYSQL


SELECT authors.id_author, authors.lastname, authors.firstname, SUM(books.quantity) as quantity_books_author 
FROM authors
INNER JOIN books2authors ON books2authors.id_author = authors.id_author
INNER JOIN books ON books2authors.id_book = books.id_book
GROUP BY authors.id_author
ORDER BY authors.lastname

##

SELECT 
    authors.id_author, 
    authors.lastname, 
    authors.firstname, 
    books.title,    
    MAX(books.cost) as max_cost_books_author 
FROM authors
INNER JOIN books2authors ON books2authors.id_author = authors.id_author
INNER JOIN books ON books2authors.id_book = books.id_book

WHERE books.cost IN ( SELECT 
    MAX(books.cost) as max_cost_books_author 
FROM authors
INNER JOIN books2authors ON books2authors.id_author = authors.id_author
INNER JOIN books ON books2authors.id_book = books.id_book
GROUP BY authors.id_author)

GROUP BY authors.id_author;



##
DROP PROCEDURE IF EXISTS makeOrder;

DELIMITER $$
 
CREATE PROCEDURE IF NOT EXISTS makeOrder(
    in  book_id int(11), 
    in  order_count  int(10))
BEGIN	
       
    DECLARE order_max_quntity int;
    DECLARE book_cost double;
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
      BEGIN
        --   ERROR
      ROLLBACK;
    END;

    DECLARE EXIT HANDLER FOR SQLWARNING
     BEGIN
        --   WARNING
     ROLLBACK;
    END;
    
    SELECT 
    	books.quantity INTO order_max_quntity        
    FROM books 
    WHERE books.id_book = book_id;
    
    SELECT    	
        books.cost INTO book_cost
    FROM books 
    WHERE books.id_book = book_id;     
 
    IF order_max_quntity >= order_count THEN
 		START TRANSACTION;
        
        UPDATE books SET books.quantity = (books.quantity - order_count) WHERE books.id_book = book_id;
        
        INSERT INTO sales (id_books, date, quantity, cost) 
        VALUES(book_id, CURRENT_DATE(), order_count, (book_cost * order_count));
        
        COMMIT;
    END IF;
    
    
 
END$$


/* ---------------------------------------------------------------------------- */
PHP

/* yii2*/

namespace app\components\helpers;

use yii;
use PhpImap\Mailbox as ImapMailbox;
use PhpImap\IncomingMail;
use PhpImap\IncomingMailAttachment;
use app\components\helpers\CommonHelper;
use app\models\DrowrofRegistaration;
use app\models\DrowrofErrors;


class ImapHelper extends \yii\base\Object
{

    public function getImapPathFiles()
    {
        return  Yii::$app->params['imapBaseDir'] . Yii::$app->params['ImapPathFiles'];
    }


    public function connect()
    {

        $fi = new \FilesystemIterator($this->imapPathFiles);
        foreach($fi as $value){
            unlink($value->getPathname());
        }


        $mailbox = new ImapMailbox('{imap.yandex.ru:993/imap/ssl/novalidate-cert}INBOX', 
        							Yii::$app->params['emailLogin'], 
        							Yii::$app->params['emailPass'],  
        							Yii::$app->params['imapPathFiles']);
        return $mailbox;
    }

    public function getMailboxAllModels($mailbox)
    {
        $mailboxData = $mailbox->searchMailBox($this->creteriaMailSearch(CommonHelper::data()->getParam('nameThemeEmailDrowrof')));

        $it = new \ArrayIterator($mailboxData);
        $mailboxAllModels = [];
        $createMailboxData = function(\Iterator $iterator) use (&$mailboxAllModels, $mailbox) {
            $current = $iterator->current();
            $mail = $mailbox->getMail($current);
            $attachments = $mail->getAttachments();
            $attachment = array_shift($attachments);
            $name = $attachment->name;
            $name = preg_replace('/.gpg/', '', $name);
            $compareStatus = DrowrofRegistaration::getCompareStatus($name);
            if($compareStatus == DrowrofRegistaration::IS_COMPARE_NOT_MAKE)
                $mailboxAllModels[] = [
            							'mailId'=> $current, 
            							'mailDate' => $mail->date, 
            							'name'=> $name, 
            							'isCompare'=> $compareStatus
            						];
            return true;
        };
        iterator_apply($it,  $createMailboxData, array($it));
        return $mailboxAllModels;
    }

    public function getMailboxAllErrorsModels($mailbox)
    {
        $mailboxData = $mailbox->searchMailBox($this->creteriaMailSearch(CommonHelper::data()->getParam('nameThemeEmailErrorDrowrof')));
        $it = new \ArrayIterator($mailboxData);
        $mailboxAllModels = [];
        $createMailboxData = function(\Iterator $iterator) use (&$mailboxAllModels, $mailbox) {
            $current = $iterator->current();
            $mail = $mailbox->getMail($current);
            $attachments = $mail->getAttachments();
            $attachment = array_shift($attachments);
            $name = $attachment->name;
            $name = preg_replace('/.gpg/', '', $name);
            if(!DrowrofErrors::isCompared($name))
                $mailboxAllModels[] = [
            							'mailId'=> $current, 
            							'mailDate' => $mail->date, 
            							'name'=> $name, 
            							'isCompare'=> DrowrofErrors::ERROR_FILE_NOT_APPLY
            ];
            return true;
        };
        iterator_apply($it,  $createMailboxData, array($it));
        return $mailboxAllModels;
    }

    public function creteriaMailSearch($searchString)
    {
        $date = new \DateTime();
        $date->sub(new \DateInterval('P30D'));
        $search = 'SUBJECT "' . $searchString . '"' . ' ' . 'SINCE "' . $date->format('j F Y') . '"';
        return $search;
    }

}

//

namespace app\modules\admin\controllers;

use Yii;
use app\models\Drowrof;
use app\models\search\Drowrof as DrowrofSearch;
use app\models\DrowrofMode;
use app\models\DrowrofRegistaration;
use app\models\DrowrofTempPayId;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;

/**
 * DrowrofController implements the CRUD actions for Drowrof model.
 */
class DrowrofController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete'   => ['POST'],
                    'activate' => ['POST'],
                    'reportSend' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Lists all Drowrof models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new DrowrofSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        $drowrofMode = new  DrowrofMode;
        $mode = $drowrofMode->getSendMode();
        $isMakeReestr = DrowrofRegistaration::isMakeReestr();

        return $this->render('index', [
            'searchModel'  => $searchModel,
            'dataProvider' => $dataProvider,
            'mode'  => $mode,
            'isMakeReestr' => $isMakeReestr,
        ]);
    }
    
    
    public function actionGetReestr()
    {
        $model = new Drowrof();
        $drowrofReestrs = $model->getDrowrofReestrs();
        $drowrofMode = new  DrowrofMode;
        $mode = $drowrofMode->getGetMode();
        return $this->render('recive', ['drowrofReestrs'=> $drowrofReestrs, 'mode'=> $mode]);
    }

    public function actionGetErrors()
    {
        $model = new Drowrof();
        $drowrofErrors = $model->getDrowrofErrors();

        return $this->render('error', ['drowrofErrors'=> $drowrofErrors]);
    }

    public function actionViewErrors()
    {
        $model  = new Drowrof();
        $status = \Yii::$app->request->get('status');
        $drowrofListErrors = $model->getListDrowrofErrors($status);

        return $this->render('list', ['drowrofListErrors'=> $drowrofListErrors, 'model' => $model, 'status' => $status]);
    }

    public function actionActivate()
    {
        $payId  = \Yii::$app->request->post('payId');
        $model  = new Drowrof();
        $result = (bool)$model->setStatusSuccessToItemPay($payId);

        return json_encode($result);exit;
    }

    public function actionDownloadReestr()
    {
        DrowrofMode::sendFile();
    }

    public function actionDownloadReport()
    {
        DrowrofTempPayId::sendFile();
    }


    public function actionMakeReestr()
    {
        $countIterateToSendDrowrof = Drowrof::getCountIterateToSendDrowrof();
        if (!$countIterateToSendDrowrof){
            return json_encode([Drowrof::ERROR_NO_DATA_TO_MAKE_REESTR=> 'Not data']);
        }

        $outPut = '';
        $error = '';
        \chdir('./..');
        $command = escapeshellcmd('php yii drowrof/send-data-to-drowrof ' . DrowrofMode::MODE_HANDLE);
        \exec($command, $outPut, $error);

        if($error == 0){
            return true;
        }else{
            return false;
        }
    }

    public function actionSendReestr()
    {
        $outPut = '';
        $error = '';
        \chdir('./..');
        $command = escapeshellcmd('php yii drowrof/send-in-handle-mode-data-to-drowrof ');
        \exec($command, $outPut, $error);

        if($error == 0){
            return true;
        }else{
            return false;
        }
    }


    public function actionDeleteReestr()
    {
        $outPut = '';
        $error = '';
        \chdir('./..');
        $command = escapeshellcmd('php yii drowrof/delete-in-handle-mode-data-to-drowrof ');
        \exec($command, $outPut, $error);

        if($error == 0){
            return true;
        }else{
            return false;
        }
    }

    public function actionApplayReestr($mail_id = null)
    {
        $outPut = '';
        $error = '';
        \chdir('./..');
        $command = escapeshellcmd("php yii drowrof/get-data-to-drowrof $mail_id");
        \exec($command, $outPut, $error);

        if($error == 0){
            return true;
        }else{
            return false;
        }
    }

    public function actionApplayErrorsReestr($mail_id = null)
    {
        $outPut = '';
        $error = '';
        \chdir('./..');
        $command = escapeshellcmd("php yii drowrof/get-errors-to-drowrof $mail_id");
        \exec($command, $outPut, $error);

        if($error == 0){
            return json_encode(['success'=> true, 'list'=>$outPut]);
        }else{
            return false;
        }
    }


    public function actionSetModeSendReestr($mode)
    {
        $result = DrowrofMode::changeSendMode($mode);
        return json_encode($result);exit;
    }

    public function actionSetModeGetReestr($mode)
    {
        $result = DrowrofMode::changeGetMode($mode);
        return json_encode($result);exit;
    }

    public function actionReportSend()
    {
        $request = \Yii::$app->request;
        $status = $request->post('status', '');
        $list   = $request->post('list');

        $drowrofTempPayId = new DrowrofTempPayId;
        $result = $drowrofTempPayId->setTempData($status, $list);

        return json_encode($result);exit;
    }
}



//

namespace app\models;

use Yii;
use yii\data\ArrayDataProvider;
use yii\data\ActiveDataProvider;
use app\components\helpers\ImapHelper;

/**
 * This is the model class for table "{{%drowrof_commision}}".
 *
 * @property int $id
 * @property string $system Название организации
 * @property int $amaunt Процент по выплатам
 */
class Drowrof extends \yii\db\ActiveRecord
{

    const SYSTEM_DROWROF    = 'drowrof';
    const SYSTEM_INPLANT    = 'inplant';
    const SYSTEM_ARENDATICA = 'arendatica';

    const ERROR_NO_DATA_TO_MAKE_REESTR = 'ERROR_NO_DATA_TO_MAKE_REESTR';

    const ITERATE_COUNT_DROWROF = 100;

    const REESTR_PATH_TEMP_LIST_PAY_ID_FILE = '/var/www/reestrs/list_pay_id.tmp';

    const STATUS_SUCCESS     = 'success';
    const STATUS_ALL_ERRORS  = 'all_errors';
    const STATUS_NOT_COMPARE_ERRORS = 'not_compare_errors';
    const STATUS_COMPARE_ERRORS     = 'compare_errors';
    const STATUS_ALL                = 'all';


    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%drowrof_commision}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['amaunt'], 'default', 'value' => null],
            [['amaunt'], 'integer'],
            [['system'], 'string', 'max' => 255],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'system' => 'Система',
            'amaunt' => 'Процент,%',
        ];
    }

    public function getStatusLabels()
    {
        return [
            ''                               => '',
            self::STATUS_SUCCESS             => 'Успешные',
            self::STATUS_ALL_ERRORS          => 'Со всеми ошибками',
            self::STATUS_NOT_COMPARE_ERRORS  => 'Со не подтвержденными ошибками',
            self::STATUS_COMPARE_ERRORS      => 'Со подтвержденными ошибками',
        ];
    }


    public function getDrowrofReestrs()
    {
        $imapHelper = new ImapHelper;
        $mailbox = $imapHelper->connect();

        $mailboxAllModels = $imapHelper->getMailboxAllModels($mailbox);

        $mailboxDataProvider = new ArrayDataProvider([
            'allModels' => array_reverse($mailboxAllModels),
            'pagination'=> [
                'pageSize'=> 10,
            ],
        ]);
        return $mailboxDataProvider;
    }


    public function getDrowrofErrors()
    {
        $imapHelper = new ImapHelper;
        $mailbox = $imapHelper->connect();

        $mailboxAllErrorsModels = $imapHelper->getMailboxAllErrorsModels($mailbox);

        $mailboxErrorDataProvider = new ArrayDataProvider([
            'allModels' => array_reverse($mailboxAllErrorsModels),
            'pagination'=> [
                'pageSize'=> 10,
            ],
        ]);
        return $mailboxErrorDataProvider;
    }

    public function getListDrowrofErrors($status)
    {
        $provider = new ActiveDataProvider([
            'query' => self::getListDrowrofErrorsDependFromTypeCheck($status),
            'pagination' => [
                'pageSize' => 1,
            ],
        ]);

        return $provider;
    }

    public static function getCountIterateToSendDrowrof()
    {
        $count = self::getCountDataToSendDrowrof();
        if( $count > 0){
            return ceil($count/static::ITERATE_COUNT_DROWROF);
        }
        return false;
    }

    public static function getCountDataToSendDrowrof()
    {
        $payTableName = self::getTableNameForDataToSendDrowrof(\Yii::$app->db->tablePrefix, Pay::tableName());

        $lastPayId = DrowrofRegistaration::getLastPayId();
        $pdo  = \Yii::$app->db->pdo;
        $stm  = $pdo->prepare("
                    SELECT COUNT(*) FROM {$payTableName}  
                    WHERE  {$payTableName}.\"statusId\" = :statusId                    
                    AND {$payTableName}.\"typeId\" = :tyeId
                    AND {$payTableName}.id >  :lastPayId 
                    ");
        $stm->execute([
            ':statusId'=> Pay::STATUS_PAY,
            ':tyeId'=> Pay::TYPE_CONTRACT,
            ':lastPayId' => $lastPayId,
        ]);

        $result = $stm->fetchAll(\PDO::FETCH_ASSOC);
        return $result[0]['count'];
    }


    public static function getDataToSendDrowrof($offset = 0, $limit = 100)
    {
        $result = self::sendSqlQqueryForDataToSendDrowrof($offset, $limit);
        return $result;
    }

    protected static function sendSqlQqueryForDataToSendDrowrof($offset, $limit)
    {
        $connection  = \Yii::$app->db;
        $tablePrefix = $connection->tablePrefix;
        $pdo         = $connection->pdo;

        $lastPayId = DrowrofRegistaration::getLastPayId();

        $payTableName             = self::getTableNameForDataToSendDrowrof($tablePrefix, Pay::tableName());
        $userTableName            = self::getTableNameForDataToSendDrowrof($tablePrefix, User::tableName());
        $leaseContractsTableName  = self::getTableNameForDataToSendDrowrof($tablePrefix, LeaseContracts::tableName());
        $paymentMethodsTableName  = self::getTableNameForDataToSendDrowrof($tablePrefix, PaymentMethods::tableName());
        //
        $trasnactionLogTableName  = self::getTableNameForDataToSendDrowrof($tablePrefix, TransactionsLog::tableName());

        $stm = $pdo->prepare("
            SELECT pay_id, sum, payment_method_id, transaction_log_id, {$userTableName}.phone AS phone FROM
                
                (SELECT pay_id, sum, {$leaseContractsTableName}.payment_method_id as payment_method_id, transaction_log_id, {$leaseContractsTableName}.user_id FROM
                    (
                    SELECT 
                      {$payTableName}.id AS pay_id, 
                      {$payTableName}.sum AS sum,                      
                      {$payTableName}.transaction_log_id AS transaction_log_id
                                           
                    FROM {$payTableName}              
                              
                    WHERE  {$payTableName}.\"statusId\" = :statusId                     
                    AND {$payTableName}.\"typeId\" = :tyeId
                    AND {$payTableName}.id >  :lastPayId
                    OFFSET :offset
                    LIMIT  :limit
                    ) AS PAY_USER              
                
                INNER JOIN {$trasnactionLogTableName} 
                ON PAY_USER.transaction_log_id = {$trasnactionLogTableName}.id                
                
                INNER JOIN {$leaseContractsTableName} 
                ON {$trasnactionLogTableName}.contract_id = {$leaseContractsTableName}.id
                
                INNER JOIN {$paymentMethodsTableName} 
                ON {$leaseContractsTableName}.payment_method_id = {$paymentMethodsTableName}.id AND  {$paymentMethodsTableName}.type = :typePaymonthMethod
                 
                ) AS PAY_USER_LEASECONTRACTS
            
            INNER JOIN {$userTableName} 
            ON PAY_USER_LEASECONTRACTS.user_id = {$userTableName}.id 
        ");

        $stm->execute([
            ':statusId'=> Pay::STATUS_PAY,
            ':tyeId'=> Pay::TYPE_CONTRACT,
            ':typePaymonthMethod' => PaymentMethods::TYPE_BANK_CARD,
            ':lastPayId' => $lastPayId,
            ':offset' => $offset,
            ':limit'  => $limit,
        ]);

        $result = $stm->fetchAll(\PDO::FETCH_NUM);

        return $result;
    }

    protected static function getTableNameForDataToSendDrowrof($tablePrefix, $tableName)
    {
        return  preg_replace(['/{{%/', '/}}/'], [$tablePrefix, ''], $tableName);
    }


    public static function getComission()
    {
        return (100 - (self::getDrowrowComission() + self::getInplantComission() + self::getArendatikaComission()))/100;
    }

    public static function getDrowrowComission()
    {
        return self::find()
            ->select('amaunt')
            ->where(['system'=> self::SYSTEM_DROWROF])
            ->orderBy(['id'=> SORT_DESC])
            ->limit(1)
            ->scalar();
    }

    public static function getInplantComission()
    {
        return self::find()
            ->select('amaunt')
            ->where(['system'=> self::SYSTEM_INPLANT])
            ->orderBy(['id'=> SORT_DESC])
            ->limit(1)
            ->scalar();
    }

    public static function getArendatikaComission()
    {
        return self::find()
            ->select('amaunt')
            ->where(['system'=> self::SYSTEM_ARENDATICA])
            ->orderBy(['id'=> SORT_DESC])
            ->limit(1)
            ->scalar();
    }

    public function saveListPayIdToSendDataToDrowrof($lastInsertId)
    {
        foreach($this->readTransactionLogIdFile(self::REESTR_PATH_TEMP_LIST_PAY_ID_FILE) as $value){
            DrowrofSendPayId::insertPayId($value, $lastInsertId);
        }
    }

    public function changeStatusAfterSendDataToDrowrof()
    {
        foreach($this->readTransactionLogIdFile(self::REESTR_PATH_TEMP_LIST_PAY_ID_FILE) as $value){
            Yii::$app->db->createCommand("UPDATE {{%transactions_log}} SET drowrof_status = :ts_status  FROM {{%pay}} as p WHERE p.transaction_log_id = {{%transactions_log}}.id AND p.id = :pay_id",
                [':pay_id'=> $value,':ts_status'=> TransactionsLog::DROWROF_STATUS_SEND])
                ->execute();
        }
    }


    public function readTransactionLogIdFile($filename)
    {
        $file = fopen($filename, 'r');
        while (($line = fgets($file)) !== false) {
            yield $line;
        }
        fclose($file);
    }
   
      
    public function setStatusSuccessToItemPay($payId)
    {
        $transaction = Yii::$app->db->beginTransaction();
        try{
            $pay = Pay::findOne($payId);
            TransactionsLog::updateAll(['drowrof_status'=> TransactionsLog::DROWROF_STATUS_SUCCESS], ['id'=> $pay->transaction_log_id]);
            DrowrofSendPayId::updateAll(['is_checked'=> DrowrofSendPayId::CHECKED], ['pay_id'=> $payId]);
            $transaction->commit();
            return true;
            } catch (\Exception $e) {
                $transaction->rollBack();
                return false;
                throw $e;
            } catch (\Throwable $e) {
                $transaction->rollBack();
                return false;
                throw $e;
            }
        }
}


//

namespace app\models;

use Yii;

class DrowrofMode extends \yii\db\ActiveRecord
{

    const MODE_TYPE_SEND = 'send';
    const MODE_TYPE_GET  = 'get';

    const MODE_AUTO   = 'auto';
    const MODE_HANDLE = 'handle';


    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%drowrof_mode}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['mode', 'name'], 'string', 'max' => 255],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'mode' => 'Mode',
            'name' => 'Name',
        ];
    }

    public static function changeSendMode($mode)
    {
        return self::updateAll(['name'=> $mode], ['mode'=> self::MODE_TYPE_SEND]);
    }

    public static function changeGetMode($mode)
    {
        return self::updateAll(['name'=> $mode], ['mode'=> self::MODE_TYPE_GET]);
    }


    public function getSendMode()
    {
        return   self::find()
                    ->select('name')
                    ->where(['mode'=> self::MODE_TYPE_SEND])
                    ->scalar();
    }

    public function getGetMode()
    {
        return   self::find()
            ->select('name')
            ->where(['mode'=> self::MODE_TYPE_GET])
            ->scalar();
    }

    public static function writeFileName($fileName)
    {
        self::updateAll(['file_name'=> $fileName], ['mode'=> self::MODE_TYPE_SEND]);
    }

    public function findSendFileName()
    {
        return   self::find()
                    ->select('file_name')
                    ->where(['mode'=>self::MODE_TYPE_SEND])
                    ->scalar();
    }

    public function sendFile()
    {
        $fileName = $fileName = $this->findSendFileName();
        $fileName = preg_replace('/.gpg$/', '', $fileName);
        if (file_exists($fileName)) {
            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename=' . basename($fileName));
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($fileName));

            if ($fd = fopen($fileName, 'rb')) {
                while (!feof($fd)) {
                    print fread($fd, 1024);
                }
                fclose($fd);
            }
            exit;
        }
    }

    public function isGetModeHidden()
    {
        $disabled = '';
        if ($this->getGetMode() == self::MODE_AUTO){
            $disabled = 'hidden';
        }
        return $disabled;
    }
}



//

namespace app\models;

use Yii;


class DrowrofRegistaration extends \yii\db\ActiveRecord
{

    const STATUS_NOT_MAKE = 1;
    const STATUS_MAKE = 2;
    const STATUS_SEND = 3;

    const IS_COMPARE_NOT_MAKE = 1;
    const IS_COMPARE_MAKE = 2;
    const IS_COMPARE_ERROR = 3;


    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%drowrof_registration}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['pay_id', 'file_name', 'created_at'], 'required'],
            [['pay_id', 'created_at', 'status', 'is_compare'], 'integer'],
            [['file_name', 'commision'], 'string'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' =>             'ID',
            'pay_id' =>         'Последний pay_id',
            'status' =>         'Статус реестра',
            'is_compare' =>     'Сверен ли реестр',
            'file_name' =>      'Имя переданного файла',
            'commision' =>      'Комиссия общая и конкретных предприятий',
            'created_at' =>     'Дата создания',
        ];
    }

    public static function getLastPayId()
    {
        return self::find()
                    ->select('pay_id')
                    ->orderBy(['id'=> SORT_DESC])
                    ->limit(1)
                    ->scalar();
    }

    public static function setLstPayId($lastPayId, $fileName)
    {
        $comission = [
            'total'=> Drowrof::getComission(),
            Drowrof::SYSTEM_DROWROF=> Drowrof::getDrowrowComission(),
            Drowrof::SYSTEM_INPLANT=> Drowrof::getInplantComission() ,
            Drowrof::SYSTEM_ARENDATICA=> Drowrof::getArendatikaComission(),
        ];
        $comission = json_encode($comission);

        $dorrowRegestartiion = new self;
        $dorrowRegestartiion->pay_id     = $lastPayId;
        $dorrowRegestartiion->file_name  = $fileName;
        $dorrowRegestartiion->commision  = $comission;
        $dorrowRegestartiion->status     = self::STATUS_MAKE;
        $dorrowRegestartiion->created_at = time();
        $dorrowRegestartiion->save(false);
       
        return $dorrowRegestartiion->id;
    }

    public static function deleteLastRow()
    {
          $result =   self::find()
                        ->select('id')
                        //
                        ->where(['status'=> self::STATUS_MAKE])
                        ->orderBy(['id'=> SORT_DESC])
                        ->limit(1)
                        ->scalar();
          if($result){
            self::deleteAll(['id'=> $result]);
          }
    }

    public static function getRegistrationRowData($fileName)
    {
        $result = self::findOne(['file_name'=> $fileName]);
        $commision = json_decode($result->commision, true);

        return ['id'=> $result->id, 'commision'=> $commision['total']];
    }

    public static function changeStatusAfterSendDataToDrowrof()
    {
            $result =   self::find()
                ->select('id')
                ->orderBy(['id'=> SORT_DESC])
                ->where(['status'=> self::STATUS_MAKE])
                ->limit(1)
                ->scalar();
            if($result){
                self::updateAll(['status'=> self::STATUS_SEND], ['id'=> $result]);
            }
    }

    public static function isMakeReestr()
    {
        $result =   self::find()
            ->select('id')
            ->orderBy(['id'=> SORT_DESC])
            ->where(['status'=> self::STATUS_MAKE])
            ->limit(1)
            ->scalar();
        if($result){
            return true;
        }else{
            return false;
        }
    }

    public static function changeCompareStatusAfterGetDataFromDrowrof($registrationId)
    {
        self::updateAll(['is_compare'=> self::IS_COMPARE_MAKE], ['id'=> $registrationId]);
    }

    public static function getCompareStatus($name)
    {
        $name = preg_replace('/csv/', 'xml', $name);
        return self::find()
            ->select('is_compare')
            ->where(['file_name'=> $name])
            ->limit(1)
            ->scalar();
    }
}



use yii\helpers\Html;
use yii\grid\GridView;
use app\models\TransactionsLog;
use app\models\Drowrof;

$this->title = 'Просмотр платежей';
$this->params['breadcrumbs'][] = ['label' => 'Система выплат Drowrof', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;

?>
<div class="drowrof-index">

    <h1><?= Html::encode($this->title) ?></h1>
    <input type="button" class="report-send btn btn-success" value="Добавить платежи для создания файла" style="">
    <input type="button" class="report-download btn btn-success" value="Загрузить файл" style="">
    <br />
    <span class="report-send-success hidden">Платежи добавлены</span>
    <span class="report-send-error   hidden">Платежи не добавлены, произошла ошибка</span>
    <br /><br />

    <?= GridView::widget([
        'dataProvider' => $drowrofListErrors,
        'filterModel'  => '',
        'options' => ['id' => 'errors_reestr_grid'],
        'columns' => [
            [
                'attribute'=> 'pay_id',
                'label' => 'id платежа',
                'value' => function($data) { return  $data["pay_id"]; },
                'options'=> ['width'=> '60px'],
                'filter'=> false,
            ],
            [
                'class'=> 'yii\grid\CheckboxColumn',
                //'header'=> 'Формирование excel',
                'cssClass'=> 'list_payments',
                'checkboxOptions' => function ($model, $key, $index, $column) {
                    if($model['drowrof_status'] == TransactionsLog::DROWROF_STATUS_SUCCESS){
                        return ['disabled' => false, 'data-success_id'=> $model["pay_id"], 'data-all_id'=> $model["pay_id"]];
                    }
                    if($model['drowrof_status'] == TransactionsLog::DROWROF_STATUS_ERROR){
                        return ['disabled' => false, 'data-error_id'=> $model["pay_id"], 'data-errornotcompare_id'=> $model["pay_id"], 'data-all_id'=> $model["pay_id"]];
                    }
                    if($model['drowrof_status'] == TransactionsLog::DROWROF_STATUS_ERROR_AFTER_COMPARE){
                        return ['disabled' => false, 'data-error_id'=> $model["pay_id"], 'data-errorcompare_id'=> $model["pay_id"], 'data-all_id'=> $model["pay_id"]];
                    }
                },
                'options'=> ['width'=> '20px'],
            ],
            [
                'attribute'=> 'drowrof_status',
                'filter' => Html::dropDownList('status', $status, $model->getStatusLabels()),
                'label' => 'Статус',
                'content' => function($data) {
                    $errorText = null;
                    if($data["drowrof_status"] == TransactionsLog::DROWROF_STATUS_ERROR){
                        $errorText = 'Не сверенная ошибка';
                    }
                    if($data["drowrof_status"] == TransactionsLog::DROWROF_STATUS_ERROR_AFTER_COMPARE){
                        $errorText = 'Сверенная ошибка';
                    }
                    if($data["drowrof_status"] == TransactionsLog::DROWROF_STATUS_SUCCESS){
                        $errorText = 'Успешный платеж';
                    }
                    return  $errorText;
                },
                'options'=> ['width'=> '100px'],
            ],
            [
                'attribute'=> 'sum',
                'label' => 'Сумма платежа',
                'value' => function($data) {
                    $commision = $data["commision"];
                    $totalComission = json_decode($commision, true);
                    return  $data["sum"] * $totalComission['total'];
                },
                'options'=> ['width'=> '120px'],
                'filter'=> false,
            ],
            [
                'attribute'=> 'pay_date',
                'label' => 'Дата и время платежа',
                'value' => function($data) { return  date('Y-m-d H:s:i', $data["pay_date"]); },
                'options'=> ['width'=> '160px'],
                'filter'=> false,
            ],
            [
                'attribute'=> 'phone',
                'label' => 'Данные пользователя',
                'format'=> 'html',
                'value' => function($data) {
                    return  Html::a($data["first_name"] . ' ' . $data["last_name"], ['/admin/user/view', 'id'=> $data['user_id']], ['class'=> 'text-primary'])
                            . Html::tag('br')
                            . $data["phone"]
                            . Html::tag('br')
                            . $data["email"]
                            . Html::tag('br')
                            . $data["ip"]
                            . Html::tag('br');
                },
                'options'=> ['width'=> '260px'],
                'filter'=> false,
            ],
            [
                'attribute'=> 'phone',
                'label' => 'Данные drowrof',
                'format'=> 'html',
                'value' => function($data) {

                    $created_at = isset($data["drowrof_created_at"]) ? 'дата ' . $data["drowrof_created_at"] . Html::tag('br') : '';
                    $number = isset($data["drowrof_number"]) ? 'номер ' . $data["drowrof_number"] . Html::tag('br') : '';
                    $summ  = isset($data["drowrof_sum"])   ? 'сумма' . $data["drowrof_sum"] . Html::tag('br') : '';
                    $code  = isset($data["drowrof_code"])  ? 'код ' . $data["drowrof_code"] . Html::tag('br') : '';
                    $phone = isset($data["drowrof_phone"]) ? 'телефон ' . $data["drowrof_phone"] . Html::tag('br') : '';

                    return  $created_at . $number . $summ . $code . $phone;
                },
                'options'=> ['width'=> '300px'],
                'filter'=> false,
            ],
            [
                'class' => 'yii\grid\ActionColumn',
                'template' => '{link}',
                'header' => 'Подтвердить платеж',
                'buttons' => [
                    'link' => function ($url, $model, $key){
                        if($model['drowrof_status'] != TransactionsLog::DROWROF_STATUS_SUCCESS){
                            return Html::a('Подтвердить платеж', '',
                                ['onclick'=> 'return false', 'class'=> 'text-primary', 'data-pay_id'=> $model["pay_id"],])
                            . Html::tag('br') . Html::tag('span');
                        }else{
                            return Html::tag('span', 'Платеж подтвержден');
                        }
                    },
                ],                
            ],

        ],
    ]); ?>
</div>


<?php


$this->registerJs('
        
        var attr;         
        if (location.search.search(/status=/) != -1){            
            var status = location.search.replace(\'?status=\', \'\');            
            var numberAmp = status.search(/&/);
            status = status.slice(0, numberAmp);            
            status = (numberAmp != -1  || status == "") ? status : status + "s";
        }else{
            var status = "";
        }  
        
        if(status == ""){            
            $(".report_all").prop("checked", true);
            $("input.report:not(.report_all)").prop("disabled", true);
            attr = "all_id";
        }
        if(status == "' . Drowrof::STATUS_SUCCESS . '"){            
            $(".report_success").prop("checked", true);
            $("input.report:not(.report_success)").prop("disabled", true);
            attr = "success_id";
        }
        if(status == "' . Drowrof::STATUS_ALL_ERRORS . '"){            
            $(".report_error").prop("checked", true);
            $("input.report:not(.report_error)").prop("disabled", true);
            attr = "error_id";
        }
        if(status == "' . Drowrof::STATUS_NOT_COMPARE_ERRORS . '"){            
            $(".report_erorr_not_compare").prop("checked", true);
            $("input.report:not(.report_erorr_not_compare)").prop("disabled", true);
            attr = "errornotcompare_id";
        }
        if(status == "' . Drowrof::STATUS_COMPARE_ERRORS . '"){            
            $(".report_error_compare").prop("checked", true);
            $("input.report:not(.report_error_compare)").prop("disabled", true);
            attr = "errorcompare_id";
        }        
        
        $(".report-send").on("click", function(){            
            var data = {};
            data.status = status;
            
            var list = [];
            $("[data-" + attr + "].list_payments:checked").each(function(index, el){                
                list.push(el.dataset.all_id);
            });
            
            data.list = list;
            $.post("/admin/drowrof/report-send", data, function(data){                  
                if(data == true){                
                  $(".report-send-success").removeClass("hidden");
                  $(".report-send-error").addClass("hidden");
                }
                
                if(data == false){                
                  $(".report-send-success").addClass("hidden");
                  $(".report-send-error").removeClass("hidden");
                }
            });
        });
        
        var apply = window.document.getElementById("errors_reestr_grid");
        
        $(apply).on("click", function(event){
            var target = event.target;
            var isNeedEl = target.hasAttribute("data-pay_id");
            if(isNeedEl){
                var param = target.dataset.pay_id;
                var data  = {"payId": param};
                
                 $.post("/admin/drowrof/activate", data, function(data){                    
                    var compareElText = $("[data-pay_id=" + param + "]").parent();
                    console.log(data);
                    if(data == "true"){                           
                       compareElText.text("Платеж подтвержден");                
                    }        
                    if(data == "false"){
                       compareElText.find("span").text("Платеж не подтвержден, произошла ошибка");                     
                    }
                });
            }   
        });
                
        $("input.report-download").on("click", function(event){
            window.location.href = "/admin/drowrof/download-report";
                
            event.stopPropagation();
            event.preventDefault();
        });            
        
');


/* Laravel */

namespace App;

use Illuminate\Database\Eloquent\Model;

class SeleniumProccessId extends Model
{
    protected $table = 'selenium_proccessid';
	
	public $timestamps = false;
	
	protected $fillable = ['shop_id', 'proccess_id'];
	
	
	public static function deleteSeleniumProccess($shopId)
	{
		$result = self::where('shop_id', '=', $shopId)->first();
		$command = escapeshellcmd("kill -9 {$result['proccess_id']}");
		\shell_exec($command);
	}	
}

//

namespace App\Traits;

use Illuminate\Support\Facades\DB;


trait ProxyConnection
{
    private static $isPost     = 1;
    private static $isNonePost = 0;	
	public static $isGet = 1;

    public function send($url, $urlProxy, $isGet = null)
    {
        $ch = curl_init();

        if(is_array($urlProxy) && !empty($urlProxy)){
            shuffle($urlProxy);
            $proxy = $urlProxy[0];            
            $options = array(
                CURLOPT_URL => $url,
                CURLOPT_NOBODY => 0,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_AUTOREFERER => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'Mozilla/5.0 Gecko/20100101 Firefox/12.0',
                CURLOPT_TIMEOUT => 200,
                CURLOPT_CONNECTTIMEOUT => 150,
                CURLINFO_HEADER_OUT => true,
                CURLOPT_PROXY => $proxy,
                CURLOPT_POST => (isset($isGet) ? self::$isNonePost : self::$isPost),//self::$isPost
            );

        }else{
            
            $options = array(
                CURLOPT_URL => $url,
                CURLOPT_NOBODY => 0,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_AUTOREFERER => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'Mozilla/5.0 Gecko/20100101 Firefox/12.0',
                CURLOPT_TIMEOUT => 200,
                CURLOPT_CONNECTTIMEOUT => 150,
                CURLINFO_HEADER_OUT => true,
                CURLOPT_POST => (isset($isGet) ? self::$isNonePost : self::$isPost),//self::$isPost
            );

        }
       
        curl_setopt_array($ch, $options);
        $content = curl_exec($ch);
        return $content;
    }

    public function getShopIpPortProxies($shoId)
    {
        $result =  DB::table('proxies')
            ->join('proxy_shop', 'proxies.id', '=', 'proxy_shop.proxy_id')
            ->select(DB::raw('CONCAT_WS(":", proxies.ip, proxies.port) proxies'))
            ->where('proxy_shop.shop_id', $shoId)
            ->orderBy('proxy_shop.id')
            ->get();
			
        return $result->pluck('proxies')->toArray();
    }
}

//

namespace App;

use Illuminate\Database\Eloquent\Model;

class WebDriver extends Model
{
    protected $table = 'webdrivers';
    protected $fillable = [ 'proxy', 'port'];

    public function shops() {
        return $this->belongsToMany('App\Shop', 'shop_webdrivers', 'webdriver_id', 'shop_id');
    }

    public function canSave($shops, $driver_id) {
        foreach ( $shops as $id ) {
            $shop = Shop::find($id);
            if ($shop) {
                $builder = $shop->webdrivers();
                if ($driver_id > 0) {
                    $builder->where('webdriver_id', '<>', $driver_id)->get();
                }
                $webDrivers = $builder->get();
                if ( count($webDrivers) > 0 ) {
                    return false;
                }
            }
        }

        return true;
    }

    public function saveDriver($request) {
        $data = $request->except('_token');
        if (isset( $data['shops'] )) {
            if (!$this->canSave($data['shops'], isset($this->id) ? $this->id : 0)) {
                return ['error' => 'Web drivers for the shops already exist'];
            }
        }

        if ($this->fill($data)->save()) {
            $data['shops'] = $data['shops'] ?? array();
            $this->shops()->sync($data['shops']);

            return ['status' => 'Web Driver was updated successfully'];
        }

        return ['error' => 'Insertion error in the Database'];
    }

}








