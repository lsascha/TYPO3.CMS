<?php
namespace TYPO3\CMS\Workspaces\Hook;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Authentication\PreviewUserAuthentication;

/**
 * Hook for checking if the preview mode is activated
 * preview mode = show a page of a workspace without having to log in
 */
class PreviewHook
{
    /**
     * the GET parameter to be used
     *
     * @var string
     */
    protected $previewKey = 'ADMCMD_prev';

    /**
     * preview configuration
     *
     * @var array
     */
    protected $previewConfiguration = false;

    /**
     * Hook after the regular BE user has been initialized
     * if there is a preview configuration
     * the BE user of the preview configuration gets initialized and
     * is used instead for the current request, overriding any existing
     * authenticated backend user.
     *
     * @param array $params holding the BE_USER object
     * @param \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController $pObj
     */
    public function initializePreviewUser(&$params, &$pObj)
    {
        $this->previewConfiguration = $this->getPreviewConfiguration();
        // if there is a valid BE user, and the full workspace should be previewed, the workspacePreview option should be set
        $workspaceUid = (int)$this->previewConfiguration['fullWorkspace'];
        if ($workspaceUid > 0) {
            $previewUser = GeneralUtility::makeInstance(PreviewUserAuthentication::class);
            $previewUser->setWebmounts([$pObj->id]);
            if ($previewUser->setTemporaryWorkspace($workspaceUid)) {
                $params['BE_USER'] = $previewUser;
                $pObj->beUserLogin = true;
            } else {
                $params['BE_USER'] = null;
                $pObj->beUserLogin = false;
            }
        }

        // If "ADMCMD_noBeUser" is set, then ensure that there is no workspace preview and no BE User logged in.
        // This option is solely used to ensure that a be user can preview the live version of a page in the
        // workspace preview module.
        if (GeneralUtility::_GET('ADMCMD_noBeUser')) {
            $params['BE_USER'] = null;
            $pObj->beUserLogin = false;
            // Caching is disabled, because otherwise generated URLs could include the ADMCMD_noBeUser parameter
            $pObj->set_no_cache('GET Parameter ADMCMD_noBeUser was given', true);
        }
    }

    /**
     * Looking for an ADMCMD_prev code, looks it up if found and returns configuration data.
     * Background: From the backend a request to the frontend to show a page, possibly with
     * workspace preview can be "recorded" and associated with a keyword.
     * When the frontend is requested with this keyword the associated request parameters are
     * restored from the database AND the backend user is loaded - only for that request.
     * The main point is that a special URL valid for a limited time,
     * eg. http://localhost/typo3site/index.php?ADMCMD_prev=035d9bf938bd23cb657735f68a8cedbf will
     * open up for a preview that doesn't require login. Thus it's useful for sending in an email
     * to someone without backend account.
     * This can also be used to generate previews of hidden pages, start/endtimes, usergroups and
     * those other settings from the Admin Panel - just not implemented yet.
     *
     * @throws \Exception
     * @return array Preview configuration array from sys_preview record.
     */
    public function getPreviewConfiguration()
    {
        $inputCode = $this->getPreviewInputCode();
        // If input code is available and shall not be ignored, look up the settings
        if ($inputCode && $inputCode !== 'IGNORE') {
            // "log out"
            if ($inputCode === 'LOGOUT') {
                setcookie($this->previewKey, '', 0, GeneralUtility::getIndpEnv('TYPO3_SITE_PATH'));
                if ($GLOBALS['TYPO3_CONF_VARS']['FE']['workspacePreviewLogoutTemplate']) {
                    $templateFile = PATH_site . $GLOBALS['TYPO3_CONF_VARS']['FE']['workspacePreviewLogoutTemplate'];
                    if (@is_file($templateFile)) {
                        $message = file_get_contents($templateFile);
                    } else {
                        $message = '<strong>ERROR!</strong><br>Template File "'
                            . $GLOBALS['TYPO3_CONF_VARS']['FE']['workspacePreviewLogoutTemplate']
                            . '" configured with $TYPO3_CONF_VARS["FE"]["workspacePreviewLogoutTemplate"] not found. Please contact webmaster about this problem.';
                    }
                } else {
                    $message = 'You logged out from Workspace preview mode. Click this link to <a href="%1$s">go back to the website</a>';
                }
                $returnUrl = GeneralUtility::sanitizeLocalUrl(GeneralUtility::_GET('returnUrl'));
                die(sprintf($message, htmlspecialchars(preg_replace('/\\&?' . $this->previewKey . '=[[:alnum:]]+/', '', $returnUrl))));
            }
            // Look for keyword configuration record:
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('sys_preview');

            $previewData = $queryBuilder
                ->select('*')
                ->from('sys_preview')
                ->where(
                    $queryBuilder->expr()->eq(
                        'keyword',
                        $queryBuilder->createNamedParameter($inputCode, \PDO::PARAM_STR)
                    ),
                    $queryBuilder->expr()->gt(
                        'endtime',
                        $queryBuilder->createNamedParameter($GLOBALS['EXEC_TIME'], \PDO::PARAM_INT)
                    )
                )
                ->setMaxResults(1)
                ->execute()
                ->fetch();

            // Get: Backend login status, Frontend login status
            // - Make sure to remove fe/be cookies (temporarily);
            // BE already done in ADMCMD_preview_postInit()
            if (is_array($previewData)) {
                if (empty(GeneralUtility::_POST())) {
                    // Unserialize configuration:
                    $previewConfig = unserialize($previewData['config']);
                    // For full workspace preview we only ADD a get variable
                    // to set the preview of the workspace - so all other Get
                    // vars are accepted. Hope this is not a security problem.
                    // Still posting is not allowed and even if a backend user
                    // get initialized it shouldn't lead to situations where
                    // users can use those credentials.
                    if ($previewConfig['fullWorkspace']) {
                        // If ADMCMD_prev is set the $inputCode value cannot come
                        // from a cookie and we set that cookie here. Next time it will
                        // be found from the cookie if ADMCMD_prev is not set again...
                        if (GeneralUtility::_GP($this->previewKey)) {
                            // Lifetime is 1 hour, does it matter much?
                            // Requires the user to click the link from their email again if it expires.
                            setcookie($this->previewKey, GeneralUtility::_GP($this->previewKey), 0, GeneralUtility::getIndpEnv('TYPO3_SITE_PATH'), null, null, true);
                        }
                        return $previewConfig;
                    }
                    if (GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . 'index.php?' . $this->previewKey . '=' . $inputCode === GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL')) {
                        // Return preview keyword configuration
                        return $previewConfig;
                    }
                    // This check is to prevent people from setting additional
                    // GET vars via realurl or other URL path based ways of passing parameters.
                    throw new \Exception(htmlspecialchars('Request URL did not match "'
                            . GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . 'index.php?' . $this->previewKey . '='
                            . $inputCode . '"', 1294585190));
                }
                throw new \Exception('POST requests are incompatible with keyword preview.', 1294585191);
            }
            throw new \Exception('ADMCMD command could not be executed! (No keyword configuration found)', 1294585192);
        }
        return false;
    }

    /**
     * returns the input code value from the admin command variable
     *
     * @return string Input code
     */
    protected function getPreviewInputCode()
    {
        $inputCode = GeneralUtility::_GP($this->previewKey);
        // If no inputcode and a cookie is set, load input code from cookie:
        if (!$inputCode && $_COOKIE[$this->previewKey]) {
            $inputCode = $_COOKIE[$this->previewKey];
        }
        return $inputCode;
    }

    /**
     * Set preview keyword, eg:
     * $previewUrl = GeneralUtility::getIndpEnv('TYPO3_SITE_URL').'index.php?ADMCMD_prev='.$this->compilePreviewKeyword($GLOBALS['BE_USER']->user['uid'], 120);
     *
     * @todo for sys_preview:
     * - Add a comment which can be shown to previewer in frontend in some way (plus maybe ability to write back, take other action?)
     * - Add possibility for the preview keyword to work in the backend as well: So it becomes a quick way to a certain action of sorts?
     *
     * @param string $backendUserUid 32 byte MD5 hash keyword for the URL: "?ADMCMD_prev=[keyword]
     * @param int $ttl Time-To-Live for keyword
     * @param int|null $fullWorkspace Which workspace ID to preview.
     * @return string Returns keyword to use in URL for ADMCMD_prev=
     */
    public function compilePreviewKeyword($backendUserUid, $ttl = 172800, $fullWorkspace = null)
    {
        $fieldData = [
            'keyword' => md5(uniqid(microtime(), true)),
            'tstamp' => $GLOBALS['EXEC_TIME'],
            'endtime' => $GLOBALS['EXEC_TIME'] + $ttl,
            'config' => serialize([
                'fullWorkspace' => $fullWorkspace,
                'BEUSER_uid' => $backendUserUid
            ])
        ];
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_preview')
            ->insert(
                'sys_preview',
                $fieldData
            );

        return $fieldData['keyword'];
    }

    /**
     * easy function to just return the number of hours
     * a preview link is valid, based on the TSconfig value "options.workspaces.previewLinkTTLHours"
     * by default, it's 48hs
     *
     * @return int The hours as a number
     */
    public function getPreviewLinkLifetime()
    {
        $ttlHours = (int)$GLOBALS['BE_USER']->getTSConfigVal('options.workspaces.previewLinkTTLHours');
        return $ttlHours ? $ttlHours : 24 * 2;
    }
}