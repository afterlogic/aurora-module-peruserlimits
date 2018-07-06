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
        $this->subscribeEvent('Files::GetFilesForUpload::before', [$this, 'onBeforeGetFilesForUpload']);

        $this->subscribeEvent('Files::CreateFolder::before', [$this, 'onBeforeCreateFolder']);
        $this->subscribeEvent('Files::UploadFile::before', [$this, 'onBeforeUploadFile']);
        $this->subscribeEvent('Files::UploadFile::after', [$this, 'onAfterUploadFile']);


        $this->subscribeEvent('download-file-entry::before', array($this, 'DownloadFile'));


        $this->subscribeEvent('Calendar::CreateCalendar::before', array($this, 'onBeforeCreateCalendar'));

        $this->subscribeEvent('Core::DoServerInitializations::before', array($this, 'onServerInitializations'));

        $this->subscribeEvent('System::toResponseArray::after', array($this, 'onAfterToResponseArray'));

    }

    public function GetSettings()
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        $oUser = \Aurora\System\Api::getAuthenticatedUser();
        $aRes = [];
        if ($oUser && $oUser->Role === \Aurora\System\Enums\UserRole::NormalUser) {

            $this->resetQuotas();

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

        if (isset($aArgs['Contact']) && !isset($aArgs['Contact']['Auto'])) {
            $oContactsModule = \Aurora\System\Api::GetModule('Contacts');
            if ($oContactsModule) {
                $oUser = \Aurora\System\Api::getAuthenticatedUser();

                $aFilters = [
                    '$AND' => [
                        'IdUser' => [
                            0 => $oUser->EntityId,
                            1 => '=',
                        ],
                        'Storage' => [
                            0 => 'personal',
                            1 => '=',
                        ],
                        '$OR' => [
                            '1@Auto' => [
                                0 => false,
                                1 => '=',
                            ],
                            '2@Auto' => [
                                0 => 'NULL',
                                1 => 'IS',
                            ],
                        ],
                    ],
                ];

                $iContacts = $oContactsModule->oApiContactsManager->getContactsCount($aFilters);
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

        return $mResults;
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


    public function DownloadFile() {
        $sHash = (string) \Aurora\System\Application::GetPathItemByIndex(1, '');
        $sAction = (string) \Aurora\System\Application::GetPathItemByIndex(2, '');
        $iOffset = (int) \Aurora\System\Application::GetPathItemByIndex(3, '');
        $iChunkSize = (int) \Aurora\System\Application::GetPathItemByIndex(4, '');

        if ($sAction !== 'thumb') {
            $aValues = \Aurora\System\Api::DecodeKeyValues($sHash);

            $iUserId = isset($aValues['UserId']) ? (int)$aValues['UserId'] : 0;
            $sType = isset($aValues['Type']) ? $aValues['Type'] : '';
            $sPath = isset($aValues['Path']) ? $aValues['Path'] : '';
            $sFileName = isset($aValues['Name']) ? $aValues['Name'] : '';
            $sPublicHash = isset($aValues['PublicHash']) ? $aValues['PublicHash'] : null;

            $iUserId = \Aurora\System\Api::getAuthenticatedUserId();
            if (0 < $iUserId) {
                $oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();
                $oUser = $oCoreDecorator->GetUser($iUserId);

                $this->resetQuotas();

                if ($sType === 'personal') {
                    $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($iUserId);
                    $metaFile = $this->oApiFilesManager->getFile($sUserPublicId, $sType, $sPath, $sFileName, $iOffset, $iChunkSize);

                    if (is_resource($metaFile)) {
                        $aMetadata = json_decode(stream_get_contents($metaFile), JSON_OBJECT_AS_ARRAY);
                        $iSize = $aMetadata['size'];

                        $settings = $this->GetSettings();
                        if ($settings['Vip'] === 0 && ($oUser->{$this->GetName() . '::DownloadedSize'} >= $settings['MaxDownloadsCloud']) || $settings['Vip'] === 0 && ($iSize >= $settings['MaxDownloadsCloud'])) {
                            throw new \Exception('ErrorMaxDownloadsCloud');
                        }

                        $oDateTime = new \DateTime('midnight');
                        $oUser->{$this->GetName() . '::DownloadedSize'} = $oUser->{$this->GetName() . '::DownloadedSize'} + $iSize;
                        $oUser->{$this->GetName() . '::DateTimeDownloadedSize'} = $oDateTime->format('Y-m-d H:i:s');
                        $oCoreDecorator->UpdateUserObject($oUser);
                    }
                }
            }
        }
    }

    public function onBeforeGetFilesForUpload(&$aArgs, &$mResult) {
        $aHashes = isset($aArgs['Hashes']) ? $aArgs['Hashes'] : [];

        foreach ($aHashes as $key => $sHash) {
            $aValues = \Aurora\System\Api::DecodeKeyValues($sHash);
            $iUserId = isset($aValues['UserId']) ? (int)$aValues['UserId'] : 0;
            $sType = isset($aValues['Type']) ? $aValues['Type'] : '';
            $sPath = isset($aValues['Path']) ? $aValues['Path'] : '';
            $sFileName = isset($aValues['Name']) ? $aValues['Name'] : '';

            $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($iUserId);
            $metaFile = $this->oApiFilesManager->getFile($sUserPublicId, $sType, $sPath, $sFileName);
            $iSize = 0;
            if (is_resource($metaFile)) {
                $aMetadata = json_decode(stream_get_contents($metaFile), JSON_OBJECT_AS_ARRAY);
                $iSize = $aMetadata['size'];
            }

            $settings = $this->GetSettings();
            if ($settings['Vip'] === 0 && $iSize > $settings['MaxMailAttachmentSize']) {
                throw new \Exception('ErrorMaxMailAttachmentSize');
            }
        }
    }

    public function changeVipStatus()
    {
        $sParameters = $this->oHttp->GetPost('Parameters', null);
        $aParameters = json_decode($sParameters);
        $iUserId = isset($aParameters->id) ? intval($aParameters->id) : 0;
        $bVip = isset($aParameters->vip) ? boolval($aParameters->vip) : false;

        $oMailSuiteConnector = \Aurora\System\Api::GetModule('MailSuiteConnector');
        if ($oMailSuiteConnector && $iUserId > 0) {
            $oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();
            $oUser = $oCoreDecorator->GetUser($iUserId);
            $sToken = $oMailSuiteConnector->sToken;

            $sResult = $oMailSuiteConnector->sendAction("PUT", "/account/vip", [
                'token' => $sToken,
                'email' => $oUser->PublicId,
                'vip' => $bVip
            ]);

            $oResult = json_decode($sResult);
            if (isset($oResult->result) && $oResult->result == true) {
                $oUser->{$this->GetName() . '::Vip'} = $bVip;
                $oCoreDecorator->UpdateUserObject($oUser);

                return true;
            }
        }

        return false;
    }

    public function onAfterToResponseArray(&$aArgs, &$mResult) {
        if (isset($aArgs[0]) && $aArgs[0] instanceof \Aurora\Modules\Core\Classes\User) {
            $oUser = $aArgs[0];
            $mResult['Vip'] = $oUser->{$this->GetName() . '::Vip'};
        }
    }

}
