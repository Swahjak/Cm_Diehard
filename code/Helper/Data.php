<?php
/**
 * Data helper
 *
 * @package     Cm_Diehard
 * @author      Colin Mollenhour
 */
class Cm_Diehard_Helper_Data extends Mage_Core_Helper_Abstract
{

    const XML_PATH_BACKEND              = 'system/diehard/backend';
    const XML_PATH_DEBUG                = 'system/diehard/debug';
    const XML_PATH_JSLIB                = 'system/diehard/jslib';
    const XML_PATH_COUNTER              = 'global/diehard/counter';

    const CACHE_TAG = 'DIEHARD';

    /** Cookie key for list of ignored blocks */
    const COOKIE_IGNORED_BLOCKS = 'diehard_ignored';

    const COOKIE_CACHE_KEY_DATA = 'dh';

    protected $_lifetime = FALSE;

    protected $_tags = array();

    protected $_blocks = array();

    protected $_defaultIgnoredBlocks = array();

    protected $_addedIgnoredBlocks = array();

    protected $_removedIgnoredBlocks = array();

    protected $_params = array();

    /**
     * @return bool
     */
    public function isEnabled()
    {
        static $enabled = NULL;
        if ($enabled === NULL) {
            $enabled = Mage::app()->useCache('diehard') && Mage::getStoreConfig(self::XML_PATH_BACKEND);
        }
        return $enabled;
    }

    /**
     * @return bool
     */
    public function isDebug()
    {
        if ($this->isAppInited()) {
            return Mage::getStoreConfigFlag(self::XML_PATH_DEBUG);
        } else {
            return Mage::getIsDeveloperMode();
        }
    }

    /**
     * @return bool
     */
    public function isAppInited()
    {
        return !! Mage::app()->getStores();
    }

    /**
     * Complete app init
     */
    public function initApp()
    {
        $appParams = Mage::registry('application_params');
        Mage::app()->initSpecified($appParams['scope_code'], $appParams['scope_type'], $appParams['options']);
    }

    /**
     * @return string
     */
    public function getFullActionName()
    {
        $request = Mage::app()->getRequest();
        if ( ! $request->getModuleName()) {
            return NULL;
        }
        return $request->getModuleName().'_'.$request->getControllerName().'_'.$request->getActionName();
    }

    /**
     * @return bool|int
     */
    public function getLifetime()
    {
        return $this->_lifetime;
    }

    /**
     * @param  bool|int $lifetime
     */
    public function setLifetime($lifetime)
    {
        if ( ! $this->isEnabled()) {
            return;
        }
        Mage::unregister('diehard_lifetime');
        $lifetime = $lifetime === FALSE ? $lifetime : (int) $lifetime;
        Mage::register('diehard_lifetime', $lifetime);
        $this->_lifetime = $lifetime;
    }

    /**
     * Add tags to the list of tags to associate with this request.
     * Duplicate tags will be filtered after all tags are added.
     *
     * @param array $tags
     * @return void
     */
    public function addTags(array $tags)
    {
        $this->_tags = array_merge($this->_tags, $tags);
    }

    /**
     * @return array
     */
    public function getTags()
    {
        $tags = array_unique($this->_tags);
        if ($tags) {
            // Filter tags by exact match
            $path = Mage::app()->getLayout()->getArea().'/diehard/ignored_tags';
            $ignoredTags = array_keys(
                Mage::app()->getConfig()->getNode($path)->asCanonicalArray()
            );
            $tags = array_diff($tags, $ignoredTags);

            // Filter tags by pattern
            $path = Mage::app()->getLayout()->getArea().'/diehard/ignored_tag_patterns';
            $node = Mage::app()->getConfig()->getNode($path);
            if ($node->hasChildren()) {
                foreach ($node->children() as $pattern) {
                    $tags = array_diff($tags, preg_grep("$pattern", $tags));
                }
            }
        }

        // Allow event observers to modify tags
        $transport = new Varien_Object(['tags' => $tags]);
        Mage::dispatchEvent('diehard_get_tags', ['transport' => $transport]);
        $tags = $transport->getData('tags');

        return $tags;
    }

    /**
     * @param string $htmlId
     * @param string $nameInLayout
     * @return void
     */
    public function addDynamicBlock($htmlId, $nameInLayout)
    {
        $this->_blocks[$htmlId] = $nameInLayout;
    }

    /**
     * @return array
     */
    public function getDynamicBlocks()
    {
        return $this->_blocks;
    }

    /**
     * @param string|Mage_Core_Block_Abstract $block
     * @return void
     */
    public function addIgnoredBlock($block)
    {
        if ($block instanceof Mage_Core_Block_Abstract) {
            $block = $block->getNameInLayout();
        }
        $this->_addedIgnoredBlocks[] = $block;
    }

    /**
     * @param string|Mage_Core_Block_Abstract $block
     * @return void
     */
    public function removeIgnoredBlock($block)
    {
        if ($block instanceof Mage_Core_Block_Abstract) {
            $block = $block->getNameInLayout();
        }
        $this->_removedIgnoredBlocks[] = $block;
    }

    /**
     * @return array
     */
    public function getAddedIgnoredBlocks()
    {
        return $this->_addedIgnoredBlocks;
    }

    /**
     * @return array
     */
    public function getRemovedIgnoredBlocks()
    {
        return $this->_removedIgnoredBlocks;
    }

    /**
     * Get the ignored blocks for the current session (cookie value)
     *
     * @return array|null
     */
    public function getIgnoredBlocks()
    {
        $ignoredBlocks = Mage::getSingleton('core/cookie')->get(self::COOKIE_IGNORED_BLOCKS);
        if ($ignoredBlocks == '-') {
          return array();
        }
        return ($ignoredBlocks === FALSE ? NULL : explode(',', $ignoredBlocks));
    }

    /**
     * Set the ignored blocks for the current session (cookie value)
     *
     * @param array $ignoredBlocks
     * @return void
     */
    public function setIgnoredBlocks($ignoredBlocks)
    {
        $ignoredBlocks = array_filter($ignoredBlocks);
        if ($ignoredBlocks) {
          $ignoredBlocks = implode(',', $ignoredBlocks);
        } else {
          $ignoredBlocks = '-';
        }
        $this->_setCookie(self::COOKIE_IGNORED_BLOCKS, $ignoredBlocks);
    }

    /**
     * Get the accumulated list of blocks which are ignored by default
     *
     * @return array
     */
    public function getDefaultIgnoredBlocks()
    {
        return $this->_defaultIgnoredBlocks;
    }

    /**
     * Add a block to the list of blocks which are ignored by default
     *
     * @param string|Mage_Core_Block_Abstract $block
     */
    public function addDefaultIgnoredBlock($block)
    {
        if ($block instanceof Mage_Core_Block_Abstract) {
            $block = $block->getNameInLayout();
        }
        $this->_defaultIgnoredBlocks[] = $block;
    }

    /**
     * Get array of all blocks excluding the ignored blocks
     *
     * @return array of htmlId => nameInLayout
     */
    public function getObservedBlocks()
    {
        $blocks = array_values($this->getDynamicBlocks());
        $ignored = (array) $this->getIgnoredBlocks();
        $ignored = array_merge($ignored, $this->_addedIgnoredBlocks);
        $ignored = array_diff($ignored, $this->_removedIgnoredBlocks);
        $blocks = array_diff($blocks, $ignored);

        $observedBlocks = array();
        foreach($this->getDynamicBlocks() as $htmlId => $nameInLayout) {
            if (in_array($nameInLayout, $blocks)) {
                $observedBlocks[$htmlId] = $nameInLayout;
            }
        }
        return $observedBlocks;
    }

    /**
     * Get all dynamic params that were set
     *
     * @return array
     */
    public function getDynamicParams()
    {
        return $this->_params;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return Cm_Diehard_Helper_Data
     */
    public function setParam($key, $value)
    {
        if ($value instanceof Varien_Object) {
            $value = $value->getData();
        }
        $this->_params[$key] = $value;
        return $this;
    }

    /**
     * Check if a fullActionName is configured as cacheable
     *
     * @param string $fullActionName
     * @return bool|int false if not cacheable, otherwise lifetime in seconds
     */
    public function isCacheableAction($fullActionName)
    {
        $area = Mage::app()->getLayout()->getArea();
        $path = $area . '/diehard/actions/' . $fullActionName;
        $lifeTime = Mage::app()->getConfig()->getNode($path);
        if ($lifeTime) {
            return intval($lifeTime);
        }
        return false;
    }

    /**
     * @return string
     */
    public function getBackendModel()
    {
        $backend = Mage::getStoreConfig(self::XML_PATH_BACKEND);
        return (string) Mage::getConfig()->getNode('global/diehard/backends/'.$backend.'/model');
    }

    /**
     * @return string
     */
    public function getBackendLabel()
    {
        $backend = Mage::getStoreConfig(self::XML_PATH_BACKEND);
        return (string) Mage::getConfig()->getNode('global/diehard/backends/'.$backend.'/label');
    }

    /**
     * @return Cm_Diehard_Model_Backend_Abstract
     */
    public function getBackend()
    {
        return Mage::getSingleton($this->getBackendModel());
    }

    /**
     * @return string|NULL
     */
    public function getJslib()
    {
        return Mage::getStoreConfig(self::XML_PATH_JSLIB);
    }

    /**
     * @return bool
     */
    public function useAjax()
    {
        return $this->getBackend()->useAjax() && $this->getJsLib();
    }

    /**
     * @return bool
     */
    public function useEsi()
    {
        return $this->getBackend()->useEsi() && $this->getJsLib();
    }

    /**
     * @return bool
     */
    public function useJs()
    {
        return $this->getBackend()->useJs() && $this->getJsLib();
    }

    /**
     * @return void
     */
    public function flush()
    {
        $this->getBackend()->flush();
    }

    /**
     * @param string $fullActionName
     * @param bool $hit
     */
    public function logRequest($fullActionName, $hit)
    {
        $config = Mage::getConfig()->getNode(self::XML_PATH_COUNTER);
        if ( ! $config || ! $config->is('enabled')) {
            return;
        }

        $counter = new Cm_Diehard_Helper_Counter($config);
        $counter->logRequest($fullActionName, $hit);
    }

    /**
     * Sets a cookie with or without full app init. Caches config to avoid full app init.
     *
     * @param string $name
     * @param string $value
     */
    protected function _setCookie($name, $value)
    {
        if ( ! $this->isAppInited()) {
            $appParams = Mage::registry('application_params');
            $cacheKey = 'DIEHARD_SETCOOKIE_'.md5(serialize($appParams));
            if ($cookieConfig = Mage::app()->loadCache($cacheKey)) {
                $cookieConfig = unserialize($cookieConfig);
            }
            if ( ! $cookieConfig) {
                $this->initApp();
                $cookie = Mage::getSingleton('core/cookie'); /* @var $cookie Mage_Core_Model_Cookie */
                $cookieConfig = array(
                    'period' => $cookie->getLifetime(),
                    'path'   => $cookie->getPath(),
                    'domain' => $cookie->getDomain(),
                    'admin'  => Mage::app()->getStore()->isAdmin(),
                    'httponly' => $cookie->getHttponly(),
                );
                Mage::app()->saveCache(serialize($cookieConfig), $cacheKey, array(Mage_Core_Model_Config::CACHE_TAG));
            }
            extract($cookieConfig);
            /* @var $period int */
            /* @var $path string */
            /* @var $domain string */
            /* @var $admin bool */
            /* @var $httponly bool */
            $expire = $period == 0 ? 0 : time() + $period;
            $secure = $admin && isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on';
            setcookie($name, $value, $expire, $path, $domain, $secure, $httponly);
        } else {
            Mage::getSingleton('core/cookie')->set($name, $value);
        }
    }

}
