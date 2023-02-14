<?php
namespace exface\Core\CommonLogic\Security\Authenticators;

use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Exceptions\Security\AuthenticationFailedError;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Security\AuthenticatorInterface;
use exface\Core\Interfaces\Exceptions\AuthenticationExceptionInterface;
use exface\Core\CommonLogic\Security\AuthenticationToken\OTPAuthToken;
use exface\Core\Exceptions\Security\AuthenticationIncompleteError;
use exface\Core\DataTypes\WidgetVisibilityDataType;
use exface\Core\Factories\WidgetFactory;
use RobThree\Auth\TwoFactorAuth;
use exface\Core\Interfaces\UserInterface;
use exface\Core\CommonLogic\Contexts\Scopes\SessionContextScope;

/**
 * Adds a second authentication factor via TOTP compatible token generator (e.g. Microsoft Authenticator) to any other authenticator.
 * 
 * This authenticator is actually a wrapper for other other authenticators. It requires the validation
 * of a one-time-password after the primary authenticator was passed successfully. The one-time-password
 * can be generated by any TOTP-compatible authenticator app like Google Authenticator or Mircrosoft Authenticator.
 * 
 * Once a user logs in for the first time, the authenticato will display a QR-code and asc the user to set
 * to download an authenticator and scan that QR-code. This will create a secret shared between the user
 * data in the workbench and the authenticator app. After this initial setup, the user will be able to
 * use the app as token generator to log in.
 * 
 * The secret is encrypted and stored in the user-authentication data in the metamodel.
 * 
 * Should the authenticator app get lost or reset, just delete the record for this authenticator from
 * the users authentication list (tab `Authentication` in the user editor for admins) and the initial setup
 * process will start again.
 * 
 * Example configuration to add a second factor to the `MetamodelAuthenticator`:
 * 
 * ```
 *  {
 *      "class": "\\exface\\Core\\CommonLogic\\Security\\Authenticators\\TwoFactorTOTPAuthenticator",
 *      "id": "2FA_AUTH",
 *      "primary_authenticator": {
 *          "class": "\\exface\\Core\\CommonLogic\\Security\\Authenticators\\MetamodelAuthenticator"
 *      }
 *  }
 *  
 * ```
 * 
 * @author Andrej Kabachnik
 *
 */
class TwoFactorTOTPAuthenticator extends AbstractAuthenticator
{    
    const SECRET_NAME_DEFAULT = 'Default authenticator';
    const SESSION_USERNAME = 'otp_username';
    const SESSION_SECRET = 'otp_secret';
    
    /**
     * @var AuthenticatorInterface
     */
    private $primaryAuthenticator = null;
    
    /**
     * Authenticator configuration for the first factor
     * 
     * @uxon-property primary_authenticator
     * @uxon-type \exface\Core\CommonLogic\Security\Authenticators\AbstractAuthenticator
     * @uxon-template {"class": "\exface\Core\CommonLogic\Security\Authenticators\MetamodelAuthenticator"}
     * 
     * @param UxonObject $uxon
     * @return TwoFactorTOTPAuthenticator
     */
    protected function setPrimaryAuthenticator(UxonObject $uxon) : TwoFactorTOTPAuthenticator
    {
        $class = $uxon->getProperty('class');
        $uxon->unsetProperty('class');
        $authenticator = new $class($this->getWorkbench());
        
        if ($uxon->getProperty('id') === null) {
            $uxon->setProperty('id', $this->getId());
        }
        $authenticator->importUxonObject($uxon);
        
        $this->primaryAuthenticator = $authenticator;
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\AuthenticationProviderInterface::authenticate()
     */
    public function authenticate(AuthenticationTokenInterface $token) : AuthenticationTokenInterface
    {
        // If the token is an OTP-token, we are validating the second factor,
        // otherwise - the first factor
        if ($token instanceof OTPAuthToken) {
            if ($token->getUsername() !== $this->getTFAUsernameFromSession()) {
                throw new AuthenticationFailedError($this, 'First and second factor usernames do not match!', null, null, $token);
            }
            $user = $this->getUserFromToken($token);
            $userSecret = $this->getTFASecretFromUser($token->getUsername());
            // If there is no secret stored in the user properties, we are setting up
            // a new authenticator. In this case, a secret MUST be present in the session!
            if ($userSecret === null) {
                $sessionSecret = $this->getTFASecretFromSession();
                if ($sessionSecret === null) {
                    throw new AuthenticationFailedError($this, 'Multi-factor authentication not properly set up for user "' . $token->getUsername() . '"');
                }
            }
            
            // Now validate the tokens password with the loaded secret. If validation
            // successful, make sure, the secret is stored in the user properties.
            // If validation fails, leave everything as-is and wait for the next attempt
            if (false === $this->getTFA()->verifyCode($userSecret ?? $sessionSecret, $token->getPassword())) {
                throw new AuthenticationFailedError($this, 'One-time password not valid. Please try again!', null, null, $token);
            } else {
                // If the secret came from the session, put it into the user properties
                if ($sessionSecret !== null) {
                    $props = new UxonObject([
                        self::SECRET_NAME_DEFAULT => $sessionSecret 
                    ]);
                } else {
                    $props = null;
                }
                $this->logSuccessfulAuthentication($user, $token->getUsername(), $props);
                // Remove everything from the session
                $this->unsetSessionVars();
            }
        } else {
            try {
                $this->primaryAuthenticator->authenticate($token);
            } catch (AuthenticationExceptionInterface $e) {
                $this->unsetSessionVars();
                throw new AuthenticationFailedError($this, $e->getMessage(), null, $e, $token);
            }
            
            // Prepare the session for OTP validation
            $this->unsetSessionVars();
            $this->setTFAUsernameInSession($token->getUsername());
            
            // Throw a special exception
            throw new AuthenticationIncompleteError($this, 'Please provide a one-time-password', null, null, $token);
        }
        return $token;
    }
    
    public function isSupported(AuthenticationTokenInterface $token) : bool
    {
        return ($token instanceof OTPAuthToken) || $this->primaryAuthenticator->isSupported($token);
    }    
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\SecurityManagerInterface::isAuthenticated()
     */
    public function isAuthenticated(AuthenticationTokenInterface $token) : bool
    {
        return $this->primaryAuthenticator->isAuthenticated($token);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Security\Authenticators\AbstractAuthenticator::getNameDefault()
     */
    protected function getNameDefault() : string
    {
        return $this->primaryAuthenticator->getName();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Security\Authenticators\AbstractAuthenticator::createLoginWidget()
     */
    public function createLoginWidget(iContainOtherWidgets $container) : iContainOtherWidgets
    {
        $username = $this->getTFAUsernameFromSession();
        if (null !== $username && '' !== $username) {
            try {
                $userSecret = $this->getTFASecretFromUser($username);
                if ($userSecret === null) {
                    $sessionSecret = $this->getTFASecretFromSession();
                    if ($sessionSecret === null) {
                        $sessionSecret = $this->getTFA()->createSecret();
                        $this->setTFASecretInSession($sessionSecret);
                    }
                    return $this->createLoginWidgetForRegistration($container, $username, $sessionSecret);
                } else {
                    return $this->createLoginWidgetForOTP($container, $username);
                }
            } catch (\Throwable $e) {
                $this->unsetSessionVars();
                throw $e;
            }
        }
        return $this->primaryAuthenticator->createLoginWidget($container);
    }
    
    /**
     * 
     * @param iContainOtherWidgets $container
     * @param string $username
     * @return iContainOtherWidgets
     */
    protected function createLoginWidgetForOTP(iContainOtherWidgets $container, string $username) : iContainOtherWidgets
    {
        $container->addWidget(WidgetFactory::createFromUxonInParent($container, new UxonObject([
            'widget_type' => 'Form',
            'columns_in_grid' => 1,
            'caption' => $this->getName(),
            'widgets' => [
                [
                    'widget_type' => 'InputHidden',
                    'attribute_alias' => 'USERNAME',
                    'value' => $username,
                    'disabled' => true
                ],[
                    'attribute_alias' => 'PASSWORD',
                    'caption' => 'Authenticator token'
                ],[
                    'attribute_alias' => 'AUTH_TOKEN_CLASS',
                    'value' => '\\' . OTPAuthToken::class,
                    'widget_type' => 'InputHidden'
                ]
            ],
            'buttons' => [
                [
                    'action_alias' => 'exface.Core.Login',
                    'align' => EXF_ALIGN_OPPOSITE,
                    'visibility' => WidgetVisibilityDataType::PROMOTED
                ]
            ]
        ])));
        return $container;
    }

    /**
     * 
     * @param iContainOtherWidgets $container
     * @param string $username
     * @param string $secret
     * @return iContainOtherWidgets
     */
    protected function createLoginWidgetForRegistration(iContainOtherWidgets $container, string $username, string $secret) : iContainOtherWidgets
    {
        $container->addWidget(WidgetFactory::createFromUxonInParent($container, new UxonObject([
            'widget_type' => 'Form',
            'columns_in_grid' => 1,
            'caption' => $this->getName(),
            'widgets' => [
                [
                    'widget_type' => 'Html',
                    'hide_caption' => true,
                    'value' => <<<HTML

                    <ol>
                        <h3>Set up your authenticator app</h3>
                        <li>Please download an authenticator app like 
                            <ul>
                                <li>Google Authenticator (<a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank">Android</a>, <a href="https://apps.apple.com/us/app/google-authenticator/id388497605" target="_blank">iOS</a>) or </li>
                                <li>Microsoft Authenticator (<a href="https://play.google.com/store/apps/details?id=com.azure.authenticator" target="_blank">Android</a>, <a href="https://apps.apple.com/us/app/microsoft-authenticator/id983156458" target="_blank">iOS</a>)</li>
                            </ul>
                        <li>Launch the app and choose to add a new secret</li>
                        <li>Scan the QR-code below or enter <b>{$secret}</b> manually</li>
                        <li>Enter the generated token code to register your authenticator</li>
                    </ol>
HTML
                ],[
                    'widget_type' => 'QrCode',
                    'value' => $this->getTFA()->getQRText($this->getWorkbench()->getConfig()->getOption('SERVER.TITLE'), $secret),
                    'hide_caption' => true
                ],[
                    'widget_type' => 'InputHidden',
                    'attribute_alias' => 'USERNAME',
                    'value' => $username
                ],[
                    'attribute_alias' => 'PASSWORD',
                    'caption' => 'Authenticator token'
                ],[
                    'attribute_alias' => 'AUTH_TOKEN_CLASS',
                    'value' => '\\' . OTPAuthToken::class,
                    'widget_type' => 'InputHidden'
                ]
            ],
            'buttons' => [
                [
                    'action_alias' => 'exface.Core.Login',
                    'align' => EXF_ALIGN_OPPOSITE,
                    'visibility' => WidgetVisibilityDataType::PROMOTED
                ]
            ]
        ])));
        return $container;
    }
    
    protected function unsetSessionVars() : TwoFactorTOTPAuthenticator
    {
        $ctxt = $this->getWorkbench()->getContext()->getScopeSession();
        $ctxt->unsetVariable(self::SESSION_SECRET, $this->getId());
        $ctxt->unsetVariable(self::SESSION_USERNAME, $this->getId());
        return $this;
    }
    
    /**
     * 
     * @param string $username
     * @return TwoFactorTOTPAuthenticator
     */
    protected function setTFAUsernameInSession(string $username = null) : TwoFactorTOTPAuthenticator
    {
        if ($username !== null) {
            $this->getWorkbench()->getContext()->getScopeSession()->setVariable(self::SESSION_USERNAME, $username, $this->getId());
        } else {
            $this->getWorkbench()->getContext()->getScopeSession()->unsetVariable(self::SESSION_USERNAME, $this->getId());
        }
        return $this;
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getTFAUsernameFromSession() : ?string
    {
        return $this->getWorkbench()->getContext()->getScopeSession()->getVariable(self::SESSION_USERNAME, $this->getId());
    }

    /**
     * 
     * @param string $username
     * @return UxonObject
     */
    protected function getTFAPropertiesFromUser(string $username) : UxonObject
    {
        $dataSheet = $this->getAuthenticatorData($username);
        $propsJson = ($dataSheet->getRowsDecrypted()[0] ?? [])['PROPERTIES_UXON'] ?? null;
        if ($propsJson === null || $propsJson === '') {
            return new UxonObject();
        }
        return UxonObject::fromJson($propsJson);
    }
    
    /**
     * 
     * @param UserInterface $user
     * @param string $secretName
     * @return string|NULL
     */
    protected function getTFASecretFromUser(string $username, string $secretName = null) : ?string
    {
        $authProps = $this->getTFAPropertiesFromUser($username);
        if ($secretName === null) {
            return $authProps->getProperty(array_key_first($authProps->toArray()));
        }
        return $authProps->getProperty($secretName);
    }
    
    /**
     * 
     * @param string $secret
     * @return SessionContextScope
     */
    protected function setTFASecretInSession(string $secret = null) : SessionContextScope
    {
        if ($secret === null) {
            $this->getWorkbench()->getContext()->getScopeSession()->unsetVariable(self::SESSION_SECRET, $this->getId());
        }
        return $this->getWorkbench()->getContext()->getScopeSession()->setVariable(self::SESSION_SECRET, $secret, $this->getId());
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getTFASecretFromSession() : ?string
    {
        return $this->getWorkbench()->getContext()->getScopeSession()->getVariable(self::SESSION_SECRET, $this->getId());
    }
    
    /**
     * 
     * @return TwoFactorAuth
     */
    protected function getTFA() : TwoFactorAuth
    {
        $tfa = new TwoFactorAuth();
        return $tfa;
    }
}