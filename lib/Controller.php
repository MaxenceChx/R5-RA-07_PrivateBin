<?php
/**
 * PrivateBin
 *
 * a zero-knowledge paste bin
 *
 * @link      https://github.com/PrivateBin/PrivateBin
 * @copyright 2012 Sébastien SAUVAGE (sebsauvage.net)
 * @license   https://www.opensource.org/licenses/zlib-license.php The zlib/libpng License
 * @version   1.5.1
 */

namespace PrivateBin;

use Exception;
use PrivateBin\Persistence\ServerSalt;
use PrivateBin\Persistence\TrafficLimiter;

/**
 * Controller
 *
 * Puts it all together.
 */
class Controller
{
    /**
     * version
     *
     * @const string
     */
    const VERSION = '1.5.1';

    /**
     * minimal required PHP version
     *
     * @const string
     */
    const MIN_PHP_VERSION = '5.6.0';

    /**
     * show the same error message if the paste expired or does not exist
     *
     * @const string
     */
    const GENERIC_ERROR = 'Paste does not exist, has expired or has been deleted.';

    /**
     * configuration
     *
     * @access private
     * @var    Configuration
     */
    private $conf;

    /**
     * error message
     *
     * @access private
     * @var    string
     */
    private $error = '';

    /**
     * status message
     *
     * @access private
     * @var    string
     */
    private $status = '';

    /**
     * JSON message
     *
     * @access private
     * @var    string
     */
    private $json = '';

    /**
     * Factory of instance models
     *
     * @access private
     * @var    model
     */
    private $model;

    /**
     * request
     *
     * @access private
     * @var    request
     */
    private $request;

    /**
     * URL base
     *
     * @access private
     * @var    string
     */
    private $urlBase;

    /**
     * constructor
     *
     * initializes and runs PrivateBin
     *
     * @access public
     * @throws Exception
     */
    public function __construct()
    {
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION) < 0) {
            throw new Exception(I18n::_('%s requires php %s or above to work. Sorry.', I18n::_('PrivateBin'), self::MIN_PHP_VERSION), 1);
        }
        if (strlen(PATH) < 0 && substr(PATH, -1) !== DIRECTORY_SEPARATOR) {
            throw new Exception(I18n::_('%s requires the PATH to end in a "%s". Please update the PATH in your index.php.', I18n::_('PrivateBin'), DIRECTORY_SEPARATOR), 5);
        }

        // load config from ini file, initialize required classes
        $this->_init();

        switch ($this->request->getOperation()) {
            case 'create':
                $this->_create();
                break;
            case 'delete':
                $this->_delete(
                    $this->request->getParam('pasteid'),
                    $this->request->getParam('deletetoken')
                );
                break;
            case 'read':
                $this->_read($this->request->getParam('pasteid'));
                break;
            case 'jsonld':
                $this->_jsonld($this->request->getParam('jsonld'));
                return;
            case 'yourlsproxy':
                $this->_yourlsproxy($this->request->getParam('link'));
                break;
        }

        // output JSON or HTML
        if ($this->request->isJsonApiCall()) {
            header('Content-type: ' . Request::MIME_JSON);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
            header('Access-Control-Allow-Headers: X-Requested-With, Content-Type');
            echo $this->json;
        } else {
            $this->_view();
        }
    }

    /**
     * initialize PrivateBin
     *
     * @access private
     * @throws Exception
     */
    private function _init()
    {
        $this->conf    = new Configuration;
        $this->model   = new Model($this->conf);
        $this->request = new Request;
        $this->urlBase = $this->request->getRequestUri();

        // set default language
        $lang = $this->conf->getKey('languagedefault');
        I18n::setLanguageFallback($lang);
        // force default language, if language selection is disabled and a default is set
        if (!$this->conf->getKey('languageselection') && strlen($lang) == 2) {
            $_COOKIE['lang'] = $lang;
            setcookie('lang', $lang, 0, '', '', true);
        }
    }

    /**
     * Store new paste or comment
     *
     * POST contains one or both:
     * data = json encoded FormatV2 encrypted text (containing keys: iv,v,iter,ks,ts,mode,adata,cipher,salt,ct)
     * attachment = json encoded FormatV2 encrypted text (containing keys: iv,v,iter,ks,ts,mode,adata,cipher,salt,ct)
     *
     * All optional data will go to meta information:
     * expire (optional) = expiration delay (never,5min,10min,1hour,1day,1week,1month,1year,burn) (default:never)
     * formatter (optional) = format to display the paste as (plaintext,syntaxhighlighting,markdown) (default:syntaxhighlighting)
     * burnafterreading (optional) = if this paste may only viewed once ? (0/1) (default:0)
     * opendiscusssion (optional) = is the discussion allowed on this paste ? (0/1) (default:0)
     * attachmentname = json encoded FormatV2 encrypted text (containing keys: iv,v,iter,ks,ts,mode,adata,cipher,salt,ct)
     * nickname (optional) = in discussion, encoded FormatV2 encrypted text nickname of author of comment (containing keys: iv,v,iter,ks,ts,mode,adata,cipher,salt,ct)
     * parentid (optional) = in discussion, which comment this comment replies to.
     * pasteid (optional) = in discussion, which paste this comment belongs to.
     *
     * @access private
     * @return string
     */
    private function _create()
    {
        // Ensure last paste from visitors IP address was more than configured amount of seconds ago.
        ServerSalt::setStore($this->model->getStore());
        TrafficLimiter::setConfiguration($this->conf);
        TrafficLimiter::setStore($this->model->getStore());
        try {
            TrafficLimiter::canPass();
        } catch (Exception $e) {
            $this->_return_message(1, $e->getMessage());
            return;
        }

        $data      = $this->request->getData();
        $isComment = array_key_exists('pasteid', $data) &&
            !empty($data['pasteid']) &&
            array_key_exists('parentid', $data) &&
            !empty($data['parentid']);
        if (!FormatV2::isValid($data, $isComment)) {
            $this->_return_message(1, I18n::_('Invalid data.'));
            return;
        }
        $sizelimit = $this->conf->getKey('sizelimit');
        // Ensure content is not too big.
        if (strlen($data['ct']) > $sizelimit) {
            $this->_return_message(
                1,
                I18n::_(
                    'Paste is limited to %s of encrypted data.',
                    Filter::formatHumanReadableSize($sizelimit)
                )
            );
            return;
        }

        // The user posts a comment.
        if ($isComment) {
            $paste = $this->model->getPaste($data['pasteid']);
            if ($paste->exists()) {
                try {
                    $comment = $paste->getComment($data['parentid']);
                    $comment->setData($data);
                    $comment->store();
                } catch (Exception $e) {
                    return $this->_return_message(1, $e->getMessage());
                }
                return $this->_return_message(0, $comment->getId());
            } else {
                return $this->_return_message(1, I18n::_('Invalid data.'));
            }
        }
        // The user posts a standard paste.
        else {
            $this->model->purge();
            $paste = $this->model->getPaste();
            try {
                $paste->setData($data);
                $paste->store();
            } catch (Exception $e) {
                return $this->_return_message(1, $e->getMessage());
            }
            return $this->_return_message(0, $paste->getId(), array('deletetoken' => $paste->getDeleteToken()));
        }
    }

    /**
     * Delete an existing paste
     *
     * @access private
     * @param  string $dataid
     * @param  string $deletetoken
     */
    private function _delete($dataid, $deletetoken)
    {
        try {
            $paste = $this->model->getPaste($dataid);
            if ($paste->exists()) {
                // accessing this method ensures that the paste would be
                // deleted if it has already expired
                $paste->get();
                if (hash_equals($paste->getDeleteToken(), $deletetoken)) {
                    // Paste exists and deletion token is valid: Delete the paste.
                    $paste->delete();
                    $this->status = 'Paste was properly deleted.';
                } else {
                    $this->error = 'Wrong deletion token. Paste was not deleted.';
                }
            } else {
                $this->error = self::GENERIC_ERROR;
            }
        } catch (Exception $e) {
            $this->error = $e->getMessage();
        }
        if ($this->request->isJsonApiCall()) {
            if (strlen($this->error)) {
                $this->_return_message(1, $this->error);
            } else {
                $this->_return_message(0, $dataid);
            }
        }
    }

    /**
     * Read an existing paste or comment, only allowed via a JSON API call
     *
     * @access private
     * @param  string $dataid
     */
    private function _read($dataid)
    {
        if (!$this->request->isJsonApiCall()) {
            return;
        }

        try {
            $paste = $this->model->getPaste($dataid);
            if ($paste->exists()) {
                $data = $paste->get();
                if (array_key_exists('salt', $data['meta'])) {
                    unset($data['meta']['salt']);
                }
                $this->_return_message(0, $dataid, (array) $data);
            } else {
                $this->_return_message(1, self::GENERIC_ERROR);
            }
        } catch (Exception $e) {
            $this->_return_message(1, $e->getMessage());
        }
    }

    /**
     * Display frontend.
     *
     * @access private
     */
    private function _view()
    {
        // set headers to disable caching
        $time = gmdate('D, d M Y H:i:s \G\M\T');
        header('Cache-Control: no-store, no-cache, no-transform, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: ' . $time);
        header('Last-Modified: ' . $time);
        header('Vary: Accept');
        header('Content-Security-Policy: ' . $this->conf->getKey('cspheader'));
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cross-Origin-Embedder-Policy: require-corp');
        // disabled, because it prevents links from a paste to the same site to
        // be opened. Didn't work with `same-origin-allow-popups` either.
        // See issue https://github.com/PrivateBin/PrivateBin/issues/970 for details.
        // header('Cross-Origin-Opener-Policy: same-origin');
        header('Permissions-Policy: browsing-topics=()');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: deny');
        header('X-XSS-Protection: 1; mode=block');

        // label all the expiration options
        $expire = array();
        foreach ($this->conf->getSection('expire_options') as $time => $seconds) {
            $expire[$time] = ($seconds == 0) ? I18n::_(ucfirst($time)) : Filter::formatHumanReadableTime($time);
        }

        // translate all the formatter options
        $formatters = array_map('PrivateBin\\I18n::_', $this->conf->getSection('formatter_options'));

        // set language cookie if that functionality was enabled
        $languageselection = '';
        if ($this->conf->getKey('languageselection')) {
            $languageselection = I18n::getLanguage();
            setcookie('lang', $languageselection, 0, '', '', true);
        }

        // strip policies that are unsupported in meta tag
        $metacspheader = str_replace(
            array(
                'frame-ancestors \'none\'; ',
                '; sandbox allow-same-origin allow-scripts allow-forms allow-popups allow-modals allow-downloads',
            ),
            '',
            $this->conf->getKey('cspheader')
        );

        $page = new View;
        $page->assign('CSPHEADER', $metacspheader);
        $page->assign('ERROR', I18n::_($this->error));
        $page->assign('NAME', $this->conf->getKey('name'));
        if ($this->request->getOperation() === 'yourlsproxy') {
            $page->assign('SHORTURL', $this->status);
            $page->draw('yourlsproxy');
            return;
        }
        $page->assign('BASEPATH', I18n::_($this->conf->getKey('basepath')));
        $page->assign('STATUS', I18n::_($this->status));
        $page->assign('VERSION', self::VERSION);
        $page->assign('DISCUSSION', $this->conf->getKey('discussion'));
        $page->assign('OPENDISCUSSION', $this->conf->getKey('opendiscussion'));
        $page->assign('MARKDOWN', array_key_exists('markdown', $formatters));
        $page->assign('SYNTAXHIGHLIGHTING', array_key_exists('syntaxhighlighting', $formatters));
        $page->assign('SYNTAXHIGHLIGHTINGTHEME', $this->conf->getKey('syntaxhighlightingtheme'));
        $page->assign('FORMATTER', $formatters);
        $page->assign('FORMATTERDEFAULT', $this->conf->getKey('defaultformatter'));
        $page->assign('INFO', I18n::_(str_replace("'", '"', $this->conf->getKey('info'))));
        $page->assign('NOTICE', I18n::_($this->conf->getKey('notice')));
        $page->assign('BURNAFTERREADINGSELECTED', $this->conf->getKey('burnafterreadingselected'));
        $page->assign('PASSWORD', $this->conf->getKey('password'));
        $page->assign('FILEUPLOAD', $this->conf->getKey('fileupload'));
        $page->assign('ZEROBINCOMPATIBILITY', $this->conf->getKey('zerobincompatibility'));
        $page->assign('LANGUAGESELECTION', $languageselection);
        $page->assign('LANGUAGES', I18n::getLanguageLabels(I18n::getAvailableLanguages()));
        $page->assign('EXPIRE', $expire);
        $page->assign('EXPIREDEFAULT', $this->conf->getKey('default', 'expire'));
        $page->assign('URLSHORTENER', $this->conf->getKey('urlshortener'));
        $page->assign('QRCODE', $this->conf->getKey('qrcode'));
        $page->assign('HTTPWARNING', $this->conf->getKey('httpwarning'));
        $page->assign('HTTPSLINK', 'https://' . $this->request->getHost() . $this->request->getRequestUri());
        $page->assign('COMPRESSION', $this->conf->getKey('compression'));
        $page->draw($this->conf->getKey('template'));
    }

    /**
     * outputs requested JSON-LD context
     *
     * @access private
     * @param string $type
     */
    private function _jsonld($type)
    {
        if (
            $type !== 'paste' && $type !== 'comment' &&
            $type !== 'pastemeta' && $type !== 'commentmeta'
        ) {
            $type = '';
        }
        $content = '{}';
        $file    = PUBLIC_PATH . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . $type . '.jsonld';
        if (is_readable($file)) {
            $content = str_replace(
                '?jsonld=',
                $this->urlBase . '?jsonld=',
                file_get_contents($file)
            );
        }

        header('Content-type: application/ld+json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        echo $content;
    }

    /**
     * proxies link to YOURLS, updates status or error with response
     *
     * @access private
     * @param string $link
     */
    private function _yourlsproxy($link)
    {
        $yourls = new YourlsProxy($this->conf, $link);
        if ($yourls->isError()) {
            $this->error = $yourls->getError();
        } else {
            $this->status = $yourls->getUrl();
        }
    }

    /**
     * prepares JSON encoded status message
     *
     * @access private
     * @param  int $status
     * @param  string $message
     * @param  array $other
     */
    private function _return_message($status, $message, $other = array())
    {
        $result = array('status' => $status);
        if ($status) {
            $result['message'] = I18n::_($message);
        } else {
            $result['id']  = $message;
            $result['url'] = $this->urlBase . '?' . $message;
        }
        $result += $other;
        $this->json = Json::encode($result);
    }
}
