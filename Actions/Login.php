<?php
namespace exface\Core\Actions;

use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\DataConnectionFactory;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\CommonLogic\Security\AuthenticationToken\UsernamePasswordAuthToken;
use exface\Core\Factories\ResultFactory;
use exface\Core\Factories\UserFactory;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Interfaces\Actions\iModifyContext;

/**
 * Performs an authentication attempt using the supplied login data.
 * 
 * This action can perform authentication agains the workbench itself or against a 
 * specified data connection (depending on wheter `CONNECTION` is set in the input
 * data or not).
 * 
 * @author Andrej Kabachnik
 *
 */
class Login extends AbstractAction implements iModifyContext
{
    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::SIGN_IN);
        $this->setInputObjectAlias('exface.Core.LOGIN_DATA');
        $this->setInputRowsMax(1);
        $this->setInputRowsMin(1);
    }
    
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $inputData = $this->getInputDataSheet($task);
        
        $token = $this->getAuthToken($task);
        if ($connectionSelector = $inputData->getCellValue('CONNECTION', 0)) {
            $dataConnection = DataConnectionFactory::createFromModel($this->getWorkbench(), $connectionSelector);
            $saveCred = $inputData->getCellValue('CONNECTION_SAVE', 0);
            $saveCred = $saveCred === null ? true : BooleanDataType::cast($saveCred);
            if ($saveForUserId = $inputData->getCellValue('CONNECTION_SAVE_FOR_USER', 0)) {
                $dataConnection->authenticate($token, $saveCred, UserFactory::createFromUsernameOrUid($this->getWorkbench(), $saveForUserId));
            } else {
                $dataConnection->authenticate($token, $saveCred);
            }
            $result = ResultFactory::createMessageResult($task, $this->translate('RESULT'));
        } else {
            $this->getWorkbench()->getSecurity()->authenticate($token);
            $result = ResultFactory::createRedirectToPageResult($task, $task->getPageSelector(), $this->translate('RESULT'));
        }
        
        $result->setContextModified(true);
        return $result;
    }
    
    protected function getAuthToken(TaskInterface $task) : AuthenticationTokenInterface
    {
        $inputData = $this->getInputDataSheet($task);
        $inputRow = $inputData->getRow(0);
        if ($tokenClass = $inputData->getCellValue('AUTH_TOKEN_CLASS', 0)) {
            $reflector = new \ReflectionClass($tokenClass);
            $constructorArgs = [];
            foreach ($reflector->getConstructor()->getParameters() as $param) {
                if ($param->getName() === 'facade') {
                    $constructorArgs[] = $task->getFacade();
                } else {
                    foreach ($inputRow as $key => $val) {
                        if (strcasecmp($key, $param->getName()) === 0) {
                            $constructorArgs[] = $val;
                            break;
                        }
                    }
                }
            }
            $token = $reflector->newInstanceArgs($constructorArgs);
        } else {
            $token = new UsernamePasswordAuthToken($inputRow['USERNAME'], $inputRow['PASSWORD'], $task->getFacade());
        }
        return $token;
    }
}