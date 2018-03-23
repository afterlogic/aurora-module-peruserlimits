<?php

namespace Aurora\Modules\PerUserLimits;

class Module extends \Aurora\System\Module\AbstractModule
{

    /**
     *
     * @var \CApiFilesManager
     */
    public $oApiFilesManager = null;

    public function init()
    {
        $this->oApiFilesManager = new \Aurora\Modules\PersonalFiles\Manager($this);

        $this->extendObject(
            'Aurora\Modules\Core\Classes\User',
            [
                'Vip' => ['int', 0, true],
                'UploadedFiles' => ['int', 0, true],
                'DateTimeLastUploadedFile' => ['datetime', date('Y-m-d H:i:s'), true],
                'DownloadedSize' => ['int', 0, true],
                'DateTimeDownloadedSize' => ['datetime', date('Y-m-d H:i:s'), true],
            ]
        );

        $this->subscribeEvent('Contacts::CreateContact::before', [$this, 'onBeforeCreateContact']);
        $this->subscribeEvent('Contacts::CreateGroup::before', [$this, 'onBeforeCreateGroup']);

        $this->subscribeEvent('Mail::UploadAttachment::before', [$this, 'onBeforeUploadAttachment']);

        $this->subscribeEvent('Files::CreateFolder::before', [$this, 'onBeforeCreateFolder']);
        $this->subscribeEvent('Files::UploadFile::before', [$this, 'onBeforeUploadFile']);
        $this->subscribeEvent('Files::UploadFile::after', [$this, 'onAfterUploadFile']);
        $this->subscribeEvent('Files::GetFile', array($this, 'onGetFile'), 1);

        $this->subscribeEvent('Calendar::CreateCalendar::before', array($this, 'onBeforeCreateCalendar'));

        $this->subscribeEvent('Core::DoServerInitializations::before', array($this, 'onServerInitializations'));
    }

    public function GetSettings()
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        $oUser = \Aurora\System\Api::getAuthenticatedUser();
        $aRes = [];
        if ($oUser && $oUser->Role === \Aurora\System\Enums\UserRole::NormalUser) {
            $aRes['Vip'] = $oUser->{$this->GetName() . '::Vip'};
            $aRes['MaxContacts'] = $oUser->{$this->GetName() . '::Vip'} === 0 ? $this->getConfig('MaxContacts', 10) : 0;
            $aRes['MaxContactsGroups'] = $oUser->{$this->GetName() . '::Vip'} === 0 ? $this->getConfig('MaxContactsGroups', 10) : 0;
            $aRes['MaxMailAttachmentSize'] = $oUser->{$this->GetName() . '::Vip'} === 0 ? $this->getConfig('MaxMailAttachmentSize', 10) : 0;
            $aRes['MaxFileSizeCloud'] = $oUser->{$this->GetName() . '::Vip'} === 0 ? $this->getConfig('MaxFileSizeCloud', 10) : 0;
            $aRes['MaxFilesUploadCloud'] = $oUser->{$this->GetName() . '::Vip'} === 0 ? $this->getConfig('MaxFilesUploadCloud', 10) : 0;
            $aRes['MaxFoldersCloud'] = $oUser->{$this->GetName() . '::Vip'} === 0 ? $this->getConfig('MaxFoldersCloud', 10) : 0;
            $aRes['MaxDownloadsCloud'] = $oUser->{$this->GetName() . '::Vip'} === 0 ? $this->getConfig('MaxDownloadsCloud', 10) : 0;
            $aRes['MaxCalendars'] = $oUser->{$this->GetName() . '::Vip'} === 0 ? $this->getConfig('MaxCalendars', 10) : 0;

            $aRes['DownloadedSize'] = $oUser->{$this->GetName() . '::DownloadedSize'};
            $aRes['DateTimeDownloadedSize'] = $oUser->{$this->GetName() . '::DateTimeDownloadedSize'};
        }

        return $aRes;
    }

    public function onBeforeCreateContact(&$aArgs, &$mResult)
    {
        $settings = $this->GetSettings();

        if (isset($aArgs['Contact']) && isset($aArgs['Contact']['Auto']) && !$aArgs['Contact']['Auto']) {
            $oContactsModule = \Aurora\System\Api::GetModule('Contacts');
            if ($oContactsModule) {
                $oUser = \Aurora\System\Api::getAuthenticatedUser();

                $aFilters = [
                    '$AND' => [
                        'IdUser' => $oUser->EntityId,
                        'Auto' => false
                    ]
                ];

                $aContacts = $oContactsModule->oApiContactsManager->getContacts(
                    \Aurora\Modules\Contacts\Enums\SortField::Name, \Aurora\System\Enums\SortOrder::ASC, 0, 0, $aFilters
                );

                $aContacts = \Aurora\System\Managers\Response::GetResponseObject($aContacts);
                $iContacts = count($aContacts);

                if ($settings['Vip'] === 0 && $iContacts >= $settings['MaxContacts']) {
                    throw new \Exception('ErrorMaxContacts');
                }
            }
        }
    }

    public function onBeforeCreateGroup(&$aArgs, &$mResult)
    {
        $settings = $this->GetSettings();

        $oContactsModule = \Aurora\System\Api::GetModule('Contacts');
        if ($oContactsModule) {
            $oUser = \Aurora\System\Api::getAuthenticatedUser();
            $aGroups = $oContactsModule->oApiContactsManager->getGroups($oUser->EntityId);

            $iGroups = count($aGroups);

            if ($settings['Vip'] === 0 && $iGroups >= $settings['MaxContactsGroups']) {
                throw new \Exception('ErrorMaxGroups');
            }
        }
    }

    public function onBeforeUploadAttachment(&$aArgs, &$mResult)
    {
        $aData = isset($aArgs['UploadData']) ? $aArgs['UploadData'] : [];
        $iSize = isset($aData['size']) ? $aData['size'] : 0;

        $settings = $this->GetSettings();
        if ($settings['Vip'] === 0 && $iSize > $settings['MaxMailAttachmentSize']) {
            throw new \Exception('ErrorMaxMailAttachmentSize');
        }
    }

    public function onBeforeCreateCalendar(&$aArgs, &$mResult)
    {
        $oCalendarModule = \Aurora\System\Api::GetModule('Calendar');
        if ($oCalendarModule) {
            $oUser = \Aurora\System\Api::getAuthenticatedUser();
            $aCalendars = $oCalendarModule->getCalendars($oUser->EntityId);

            $iCalendars = 0;
            if (isset($aCalendars['Calendars']) && is_array($aCalendars['Calendars']) && 0 < count($aCalendars['Calendars'])) {
                $iCalendars = count($aCalendars['Calendars']);
            }

            $settings = $this->GetSettings();
            if ($settings['Vip'] === 0 && $iCalendars >= $settings['MaxCalendars']) {
                throw new \Exception('ErrorMaxCalendars');
            }
        }
    }

    public function onBeforeUploadFile(&$aArgs, &$mResult)
    {
        $aData = isset($aArgs['UploadData']) ? $aArgs['UploadData'] : [];
        $iSize = isset($aData['size']) ? $aData['size'] : 0;

        $settings = $this->GetSettings();
        if ($settings['Vip'] === 0 && $iSize > $settings['MaxFileSizeCloud']) {
            throw new \Exception('ErrorMaxFileSizeCloud');
        }
    }

    public function onAfterUploadFile(&$aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        $iUserId = \Aurora\System\Api::getAuthenticatedUserId();
        if (0 < $iUserId) {
            $oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();
            $oUser = $oCoreDecorator->GetUser($iUserId);

            $oDateTime = new \DateTime('midnight');

            $this->resetQuotas();

            $settings = $this->GetSettings();
            if ($settings['Vip'] === 0 && $oUser->{$this->GetName() . '::UploadedFiles'} >= $settings['MaxFilesUploadCloud']) {
                throw new \Exception('ErrorMaxFilesUploadCloud');
            }

            $oUser->{$this->GetName() . '::UploadedFiles'}++;
            $oUser->{$this->GetName() . '::DateTimeLastUploadedFile'} = $oDateTime->format('Y-m-d H:i:s');
            $oCoreDecorator->UpdateUserObject($oUser);
        }
        return true;
    }

    public function onBeforeCreateFolder(&$aArgs, &$mResult)
    {
        $settings = $this->GetSettings();

        $mFolders = $this->getFolders($aArgs);
        $iFolders = is_array($mFolders) ? count($mFolders) : 0;
        if ($settings['Vip'] === 0 && $iFolders >= $settings['MaxFoldersCloud']) {
            throw new \Exception('ErrorMaxFoldersCloud');
        }
    }

    private function getFolders(&$aArgs, $sFullPath = '', &$mResults = array())
    {
        $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
        $aFiles = $this->oApiFilesManager->getFiles($sUserPublicId, $aArgs['Type'], $sFullPath, '');

        $settings = $this->GetSettings();
        $iFolders = is_array($mResults) ? count($mResults) : 0;
        foreach ($aFiles as &$fileInfo) {
            if ($fileInfo->IsFolder) {
                if ($settings['Vip'] === 0 && $iFolders >= $settings['MaxFoldersCloud']) {
                    break;
                } else {
                    $this->getFolders($aArgs, $fileInfo->FullPath, $mResults);
                    $mResults[] = $fileInfo->FullPath;
                }
            }
        }

        $this->getToken();

        return $mResults;
    }

    public function onServerInitializations(&$aArgs, &$mResult)
    {
        $this->resetQuotas();
    }

    private function resetQuotas()
    {
        $iUserId = \Aurora\System\Api::getAuthenticatedUserId();
        if (0 < $iUserId) {
            $oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();
            $oUser = $oCoreDecorator->GetUser($iUserId);

            $oDateTime = new \DateTime('midnight');
            $oLastUploadedDate = new \DateTime($oUser->{$this->GetName() . '::DateTimeLastUploadedFile'});
            $oLastDownloadDate = new \DateTime($oUser->{$this->GetName() . '::DateTimeDownloadedSize'});

            $bResetUploadedQuota = $oLastUploadedDate->getTimestamp() < $oDateTime->getTimestamp();
            $bResetDownloadedQuota = $oLastDownloadDate->getTimestamp() < $oDateTime->getTimestamp();

            if ($bResetUploadedQuota) {
                $oUser->{$this->GetName() . '::UploadedFiles'} = 0;
                $oUser->{$this->GetName() . '::DateTimeLastUploadedFile'} = date('Y-m-d H:i:s');
            }

            if ($bResetDownloadedQuota) {
                $oUser->{$this->GetName() . '::DownloadedSize'} = 0;
                $oUser->{$this->GetName() . '::DateTimeDownloadedSize'} = date('Y-m-d H:i:s');
            }

            if ($bResetUploadedQuota || $bResetDownloadedQuota) {
                $oCoreDecorator->UpdateUserObject($oUser);
            }
        }
    }

    public function onGetFile(&$aArgs, &$mResult)
    {
        $iUserId = \Aurora\System\Api::getAuthenticatedUserId();
        if (0 < $iUserId) {
            $oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();
            $oUser = $oCoreDecorator->GetUser($iUserId);

            $this->resetQuotas();

            $settings = $this->GetSettings();
            if ($settings['Vip'] === 0 && $oUser->{$this->GetName() . '::DownloadedSize'} >= $settings['MaxDownloadsCloud']) {
                throw new \Exception('ErrorMaxDownloadsCloud');
            }

            if ($aArgs['Type'] === 'personal') {
                $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
                $iOffset = isset($aArgs['Offset']) ? $aArgs['Offset'] : 0;
                $iChunkSize = isset($aArgs['ChunkSize']) ? $aArgs['ChunkSize'] : 0;
                $metaFile = $this->oApiFilesManager->getFile($sUserPublicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Id'], $iOffset, $iChunkSize);
                if (is_resource($metaFile)) {
                    $aMetadata = json_decode(stream_get_contents($metaFile), JSON_OBJECT_AS_ARRAY);

                    $oDateTime = new \DateTime('midnight');
                    $oUser->{$this->GetName() . '::DownloadedSize'} = $oUser->{$this->GetName() . '::DownloadedSize'} + $aMetadata['size'];
                    $oUser->{$this->GetName() . '::DateTimeDownloadedSize'} = $oDateTime->format('Y-m-d H:i:s');
                    $oCoreDecorator->UpdateUserObject($oUser);
                }
            }
        }
    }

    private function changeVipStatus()
    {
        $oMailSuiteConnector = \Aurora\System\Api::GetModule('MailSuiteConnector');

        if ($oMailSuiteConnector) {
            $sEmail = 'an.polikanin@foldercrate.com';
            $iVip = 1;

            $oUser = $oMailSuiteConnector->getUserByEmail($sEmail);
            $sToken = $oMailSuiteConnector->sToken;

            $sResult = $this->sendAction("PUT", "/account/vip", [
                'token' => $sToken,
                'email' => $sEmail,
                'vip' => $iVip
            ]);

            if ($sResult) {
                $oUser->{$this->GetName() . '::Vip'} = $iVip;
                $oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();
                $oCoreDecorator->UpdateUserObject($oUser);

                return $sResult;
            }

            return false;
        }
    }

}