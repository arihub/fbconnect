<?php

class FBCONNECT_CLASS_EventHandler
{
    public function onCollectButtonList( BASE_CLASS_EventCollector $event )
    {
        $faceBookDetails = FBCONNECT_BOL_Service::getInstance()->getFaceBookAccessDetails();

        if ( empty($faceBookDetails->appId) || empty($faceBookDetails->secret) ) return;

        $cssUrl = OW::getPluginManager()->getPlugin('FBCONNECT')->getStaticCssUrl() . 'fbconnect.css';
        OW::getDocument()->addStyleSheet($cssUrl);

        $button = new FBCONNECT_CMP_ConnectButton();
        $event->add(array('iconClass' => 'ow_ico_signin_f', 'markup' => $button->render()));
    }

    public function afterUserRegistered( OW_Event $event )
    {
        $params = $event->getParams();

        if ( $params['method'] != 'facebook' )
        {
            return;
        }

        $userId = (int) $params['userId'];
        $user = BOL_UserService::getInstance()->findUserById($userId);

        if ( empty($user->accountType) )
        {
            BOL_PreferenceService::getInstance()->savePreferenceValue('fbconnect_user_credits', 1, $userId);
        }
        
        $event = new OW_Event('feed.action', array(
                'pluginKey' => 'base',
                'entityType' => 'user_join',
                'entityId' => $userId,
                'userId' => $userId,
                'replace' => true,
                ), array(
                'string' => OW::getLanguage()->text('fbconnect', 'feed_user_join'),
                'view' => array(
                    'iconClass' => 'ow_ic_user'
                )
            ));
        OW::getEventManager()->trigger($event);
    }

    public function afterUserSynchronized( OW_Event $event )
    {
        $params = $event->getParams();

        if ( !OW::getPluginManager()->isPluginActive('activity') || $params['method'] !== 'facebook' )
        {
            return;
        }
        $event = new OW_Event(OW_EventManager::ON_USER_EDIT, array('method' => 'native', 'userId' => $params['userId']));
        OW::getEventManager()->trigger($event);
    }
    
    public function onCollectAccessExceptions( BASE_CLASS_EventCollector $e ) {
        $e->add(array('controller' => 'FBCONNECT_CTRL_Connect', 'action' => 'xdReceiver'));
        $e->add(array('controller' => 'FBCONNECT_CTRL_Connect', 'action' => 'login'));
    }
    
    public function onCollectAdminNotification( BASE_CLASS_EventCollector $e )
    {
        $language = OW::getLanguage();
        $configs = OW::getConfig()->getValues('fbconnect');

        if ( empty($configs['app_id']) || empty($configs['api_secret']) )
        {
            $e->add($language->text('fbconnect', 'admin_configuration_required_notification', array('href' => OW::getRouter()->urlForRoute('fbconnect_configuration'))));
        }
    }    
    
    public function getConfiguration( OW_Event $event )
    {
        $service = FBCONNECT_BOL_Service::getInstance();
        $appId = $service->getFaceBookAccessDetails()->appId;

        if ( empty($appId) )
        {
            return null;
        }
        
        $data = array(
            "appId" => $appId
        );
        
        $event->setData($data);
        
        return $data;
    }
    
    public function onAfterUserCompleteProfile( OW_Event $event )
    {
        $params = $event->getParams();
        $userId = !empty($params['userId']) ? (int) $params['userId'] : OW::getUser()->getId();

        $userCreditPreference = BOL_PreferenceService::getInstance()->getPreferenceValue('fbconnect_user_credits', $userId);
        
        if ( $userCreditPreference == 1 )
        {
            BOL_AuthorizationService::getInstance()->trackAction("base", "user_join");
            
            BOL_PreferenceService::getInstance()->savePreferenceValue('fbconnect_user_credits', 0, $userId);
        }
    }

    /**
     * @param OW_Event $event
     */
    public function onCompleteProfile( OW_Event $event )
    {
        $userId = OW::getUser()->getId();

        if ( FBCONNECT_BOL_Service::getInstance()->isEmailAlias($userId) )
        {
            $params = $event->getParams();
            $event->setData(OW::getClassInstanceArray('FBCONNECT_CTRL_CompleteProfile', $params['arguments']));
        }
    }

    public function onAfterRoute( OW_Event $event )
    {
        if ( OW::getRequest()->isAjax() )
        {
            return;
        }

        if (!OW::getUser()->isAuthenticated())
        {
            return;
        }

        $userId = OW::getUser()->getId();

        if ( OW::getUser()->isAdmin() )
        {
            return;
        }

        if ( FBCONNECT_BOL_Service::getInstance()->isEmailAlias($userId) )
        {
            OW::getRequestHandler()->addCatchAllRequestsExclude('base.complete_profile', 'BASE_CTRL_Console', 'listRsp');
            OW::getRequestHandler()->addCatchAllRequestsExclude('base.complete_profile', 'BASE_CTRL_User', 'signOut');
            OW::getRequestHandler()->addCatchAllRequestsExclude('base.complete_profile', 'INSTALL_CTRL_Install');
            OW::getRequestHandler()->addCatchAllRequestsExclude('base.complete_profile', 'BASE_CTRL_BaseDocument', 'installCompleted');
            OW::getRequestHandler()->addCatchAllRequestsExclude('base.complete_profile', 'BASE_CTRL_AjaxLoader');
            OW::getRequestHandler()->addCatchAllRequestsExclude('base.complete_profile', 'BASE_CTRL_AjaxComponentAdminPanel');
        }
    }
    
    public function genericInit()
    {
        $this->fbConnectAutoload();

        OW::getEventManager()->bind(BASE_CMP_ConnectButtonList::HOOK_REMOTE_AUTH_BUTTON_LIST, array($this, "onCollectButtonList"));
        OW::getEventManager()->bind(OW_EventManager::ON_USER_REGISTER, array($this, "afterUserRegistered"));
        OW::getEventManager()->bind(OW_EventManager::ON_USER_EDIT, array($this, "afterUserSynchronized"));
        
        OW::getEventManager()->bind('base.members_only_exceptions', array($this, "onCollectAccessExceptions"));
        OW::getEventManager()->bind('base.password_protected_exceptions', array($this, "onCollectAccessExceptions"));
        OW::getEventManager()->bind('base.splash_screen_exceptions', array($this, "onCollectAccessExceptions"));
        
        OW::getEventManager()->bind('fbconnect.get_configuration', array($this, "getConfiguration"));
        OW::getEventManager()->bind(OW_EventManager::ON_AFTER_USER_COMPLETE_PROFILE, array($this, "onAfterUserCompleteProfile"));

        OW::getEventManager()->bind(OW_EventManager::ON_AFTER_ROUTE, array($this, 'onAfterRoute'));
        OW::getEventManager()->bind('class.get_instance.BASE_CTRL_CompleteProfile', array($this, 'onCompleteProfile'));

        OW::getEventManager()->bind('base.members_only_exceptions', array($this, 'addFacebookException'));
        OW::getEventManager()->bind('base.splash_screen_exceptions', array($this, 'addFacebookException'));
        OW::getEventManager()->bind('base.password_protected_exceptions', array($this, 'addFacebookException'));

    }
    
    public function init()
    {
        $this->genericInit();
        
        OW::getEventManager()->bind('admin.add_admin_notification', array($this, "afterUserSynchronized"));
    }

    private function fbConnectAutoload()
    {
        $fbConnectAutoLoader = function ( $className )
        {
            if ( strpos($className, 'FBCONNECT_FC_') === 0 )
            {
                $file = OW::getPluginManager()->getPlugin('fbconnect')->getRootDir() . DS . 'classes' . DS . 'converters.php';
                require_once $file;

                return true;
            }
        };

        spl_autoload_register($fbConnectAutoLoader);
    }

    public function addFacebookException( BASE_CLASS_EventCollector $e )
    {
        $e->add(array('controller' => 'FBCONNECT_CTRL_Connect', 'action' => 'login'));
    }


}
