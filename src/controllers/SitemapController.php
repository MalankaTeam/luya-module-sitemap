<?php

namespace cebe\luya\sitemap\controllers;

use luya\cms\helpers\Url;
use luya\cms\models\Nav;
use luya\cms\models\NavItem;
use luya\web\Controller;
use samdark\sitemap\Sitemap;
use Yii;

/**
 * Controller provides sitemap.xml
 *
 * @author Carsten Brandt <mail@cebe.cc>
 */
class SitemapController extends Controller
{
    /**
     * @var int it's maximum path nesting
     */
    private $maxPathNesting = 30;
    
    /**
     * Return the sitemap xml content.
     *
     * @return \yii\web\Response
     */
    public function actionIndex()
    {
        $sitemapFile = Yii::getAlias('@runtime/sitemap.xml');

        // update sitemap file as soon as CMS structure changes
        $lastCmsChange = max(NavItem::find()->select(['MAX(timestamp_create) as tc', 'MAX(timestamp_update) as tu'])->asArray()->one());
        
        if (!file_exists($sitemapFile) || filemtime($sitemapFile) < $lastCmsChange) {
            $this->buildSitemapfile($sitemapFile);
        }
        
        return Yii::$app->response->sendFile($sitemapFile, null, [
            'mimeType' => 'text/xml',
            'inline' => true,
        ]);
    }

    private function buildSitemapfile($sitemapFile)
    {
        $baseUrl = Yii::$app->request->hostInfo . Yii::$app->request->baseUrl;

        // create sitemap
        $sitemap = new Sitemap($sitemapFile, true);

        // ensure sitemap is only one file
        // TODO make this configurable and allow more than one sitemap file
        $sitemap->setMaxUrls(PHP_INT_MAX);

        // add entry page
        $sitemap->addItem($baseUrl);

        // add luya CMS pages
        if ($this->module->module->hasModule('cms')) {

            // TODO this does not reflect time contraints for publishing items
            $query = Nav::find()->andWhere([
                'is_deleted' => false,
                'is_offline' => false,
                'is_draft' => false,
            ])->with(['navItems', 'navItems.lang']);
            
            if (!$this->module->withHidden) {
                $query->andWhere(['is_hidden' => false]);
            }
            
            foreach ($query->each() as $nav) {
                /** @var Nav $nav */

                $urls = [];
                foreach ($nav->navItems as $navItem) {
                    /** @var NavItem $navItem */
                    $fullUriPath = $this->getRelativeUriByNavItem($navItem);

                    $urls[$navItem->lang->short_code] = Yii::$app->request->hostInfo
                        . Yii::$app->menu->buildItemLink($fullUriPath, $navItem->lang->short_code);
                }
                $lastModified = $navItem->timestamp_update == 0 ? $navItem->timestamp_create : $navItem->timestamp_update;
                
                $sitemap->addItem($urls, $lastModified);
            }
        }

        // write sitemap files
        $sitemap->write();
    }
    
    /**
     * Get full relative URI by NavItem
     *
     * @author Robert Kuznetsov <robert@malanka.pro>
     *
     * @param NavItem $navItem object
     *
     * @return return string
     */
    private function getRelativeUriByNavItem($navItem)
    {
        $fullUriPath = $navItem->alias;
        $language = $navItem->lang->short_code;
        $parentNavId = $navItem->nav->attributes['parent_nav_id'];
        $nestingIndex = 0;
        while ($parentNavId) {
            $parentNav = Nav::find()->where([
                'is_deleted' => false,
                'is_offline' => false,
                'is_draft' => false,
                'id' => $parentNavId,
            ])->one();

            $parentNavItem = $parentNav->getActiveLanguageItem()->one();
            $alias = $parentNavItem->attributes['alias'];
            $fullUriPath = $alias . '/' . $fullUriPath;
            $parentNavId = $parentNav->attributes['parent_nav_id'];
            $nestingIndex += 1;
            if ($nestingIndex >= $this->maxPathNesting) {
                // TODO create Exception or other action for notify to customers
                break;
            }
        }

        return $fullUriPath;
    }
}
