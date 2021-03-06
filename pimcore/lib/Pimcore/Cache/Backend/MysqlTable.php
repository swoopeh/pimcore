<?php 
/**
 * Pimcore
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.pimcore.org/license
 *
 * @copyright  Copyright (c) 2009-2010 elements.at New Media Solutions GmbH (http://www.elements.at)
 * @license    http://www.pimcore.org/license     New BSD License
 */


class Pimcore_Cache_Backend_MysqlTable extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface {

    /**
     * @var Zend_Db_Adapter_Abstract
     */
    protected $db;

    /**
     * @param string $id
     * @param bool $doNotTestCacheValidity
     * @return false|null|string
     */
    public function load($id, $doNotTestCacheValidity = false) {
        $data = $this->getDb()->fetchRow("SELECT data,expire FROM cache WHERE id = ?", $id);
        if($data && isset($data["expire"]) && $data["expire"] > time()) {
            return $data["data"];
        }
        return null;
    }

    /**
     * @return Zend_Db_Adapter_Abstract
     */
    protected function getDb () {
        if(!$this->db) {
            $this->db = Pimcore_Resource::get();
        }
        return $this->db;
    }
    
    /**
     * @param string $tag
     * @return void
     */
    protected function removeTag($tag) {
        $this->getDb()->delete("cache_tags", "tag = '".$tag."'");
    }

    /**
     * @param string $id
     * @param array $tags
     * @return void
     */
    protected function saveTags($id, $tags) {
        try {
            while ($tag = array_shift($tags)) {
                try {
                    $this->getDb()->query("INSERT INTO cache_tags (id,tag) VALUES('" . $id . "', '" . $tag . "') ON DUPLICATE KEY UPDATE id = '" . $id . "'");
                }
                catch (Exception $e) {
                    Logger::warning($e);
                    if(strpos(strtolower($e->getMessage()), "is full") !== false) {
                        // it seems that the MEMORY table is on the limit an full
                        // change the storage engine of the cache tags table to InnoDB
                        $this->getDb()->query("ALTER TABLE `cache_tags` ENGINE=InnoDB");

                        // try it again
                        $tags[] = $tag;
                    } else {
                        throw $e;
                    }
                }
            }
        } catch (Exception $e) {
            Logger::error($e);
        }
    }

    /**
     * @return void
     */
    protected function clearTags () {
        $this->getDb()->query("TRUNCATE TABLE `cache_tags`");
        $this->getDb()->query("ALTER TABLE `cache_tags` ENGINE=MEMORY");
    }

    /**
     * @param string $tag
     * @return array
     */
    protected function getItemsByTag($tag) {
        $itemIds = $this->getDb()->fetchCol("SELECT id FROM cache_tags WHERE tag = ?", $tag);
        return $itemIds;
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data             Datas to cache
     * @param  string $id               Cache id
     * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  int    $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean True if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false) {

        $lifetime = $this->getLifetime($specificLifetime);

        $this->getDb()->insertOrUpdate("cache", array(
            "data" => $data,
            "id" => $id,
            "expire" => time() + $lifetime,
            "mtime" => time()
        ));
        
        if (count($tags) > 0) {
            $this->saveTags($id, $tags);
        }
        return true;
    }

    /**
     * @param  string $id
     * @return bool true if OK
     */
    public function remove($id) {

        $this->getDb()->delete("cache", "id = " . $this->getDb()->quote($id));

        // using func_get_arg() to be compatible with the interface
        // when the 2ng argument is true, do not clean the cache tags
        if(func_num_args() > 1 && func_get_arg(1) !== true) {
            $this->getDb()->delete("cache_tags", "id = '".$id."'");
        }


        return true;
    }

    /** 
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => remove too old cache entries ($tags is not used)
     * 'matchingTag'    => remove cache entries matching all given tags
     *                     ($tags can be an array of strings or a single string)
     * 'notMatchingTag' => remove cache entries not matching one of the given tags
     *                     ($tags can be an array of strings or a single string)
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @return boolean True if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array()) {

        if ($mode == Zend_Cache::CLEANING_MODE_ALL) {
            $this->clearTags();
            $this->getDb()->query("TRUNCATE TABLE `cache`");
        }
        if ($mode == Zend_Cache::CLEANING_MODE_OLD) {
            // not supported
            //$this->getDb()->delete("cache", "expire < " . time());
        }
        if ($mode == Zend_Cache::CLEANING_MODE_MATCHING_TAG || $mode == Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG) {
            foreach ($tags as $tag) {
                $items = $this->getItemsByTag($tag);
                foreach ($items as $item) {
                    // We call delete directly here because the ID in the cache is already specific for this site
                    $this->remove($item, true);
                }
                $this->getDb()->delete("cache_tags", "tag = '".$tag."'");
            }            
        }
        if ($mode == Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG) {
            
            $condParts = array("1=1");
            foreach ($tags as $tag) {
                $condParts[] = "tag != '" . $tag . "'";
            }

            $itemIds = $this->getDb()->fetchCol("SELECT id FROM cache_tags WHERE ".implode(" AND ",$condParts));
            foreach ($itemIds as $item) {
                $this->remove($item);
            }

        }

        return true;
    }
    
    
    /**
     * @param  string $id
     * @return array tags for given id
     */
    protected function getTagsById($id) {
        $itemIds = $this->getDb()->fetchCol("SELECT tag FROM cache_tags WHERE id = ?", $id);
        return $itemIds;
    }

    /**
     * @param array $tags
     * @return array
     */
    public function getIdsMatchingAnyTags($tags = array()) {
        $tags_ = array();
        foreach($tags as $tag) {
            $tags_[] = $this->getDb()->quote($tag);
        }

        $itemIds = $this->getDb()->fetchCol("SELECT id FROM cache_tags WHERE tag IN (".implode(",",$tags_).")");
        return $itemIds;
    }


    /**
     * @param array $tags
     * @return array
     */
    public function getIdsMatchingTags($tags = array()) {

        $tags_ = array();
        foreach($tags as $tag) {
            $tags_[] = " tag = ".$this->getDb()->quote($tag);
        }

        $itemIds = $this->getDb()->fetchCol("SELECT id FROM cache_tags WHERE ".implode(" AND ",$tags_));
        return $itemIds;
    }

    public function getMetadatas($id) {

        $data = $this->getDb()->fetchRow("SELECT mtime,expire FROM cache WHERE id = ?", $id);

        if (is_array($data) && isset($data["mtime"])) {
            return array(
                'expire' => $data["expire"],
                'tags' => array(),
                'mtime' => $data["mtime"]
            );
        }
        return false;
    }

    /**
     * @return array
     */
    public function getCapabilities() {
        return array(
            'automatic_cleaning' => false,
            'tags' => true,
            'expired_read' => false,
            'priority' => false,
            'infinite_lifetime' => false,
            'get_list' => false
        );
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        return $this->getDb()->fetchAll("SELECT DISTINCT (id) FROM cache_tags");
    }

    public function test($id)
    {
        $data = $this->getDb()->fetchRow("SELECT mtime,expire FROM cache WHERE id = ?", $id);
        if ($data && isset($data["expire"]) && time() < $data["expire"]) {
            return $data["mtime"];
        }
        return false;
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        return 0;
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($id, $extraLifetime)
    {
        $data = $this->getDb()->fetchRow("SELECT mtime,expire FROM cache WHERE id = ?", $id);
        if ($data && isset($data["expire"]) && time() < $data["expire"]) {
            $lifetime = (int) ($data["expire"] - $data["mtime"]);
            $this->getDb()->update("cache", array("expire" => (time() + $lifetime + (int) $extraLifetime)));
        }
        return true;
    }

    public function getIds()
    {
        return $this->getDb()->fetchAll("SELECT id from cache");
    }

    public function getIdsNotMatchingTags($tags = array())
    {
        return array();
    }
}
