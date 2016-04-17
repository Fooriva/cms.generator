<?php
namespace Mouf\Cms\Generator\Controllers;

use Mouf\Cms\Generator\Services\CMSControllerGeneratorService;
use Mouf\Controllers\AbstractMoufInstanceController;
use Mouf\Database\Patcher\DatabasePatchInstaller;
use Mouf\Database\TDBM\TDBMService;
use Mouf\Database\TDBM\Utils\TDBMDaoGenerator;
use Mouf\Html\Widgets\MessageService\Service\UserMessageInterface;
use Mouf\InstanceProxy;
use Mouf\MoufManager;
use Mouf\Mvc\Splash\Controllers\Controller;
use Mouf\Html\Template\TemplateInterface;
use Mouf\Html\HtmlElement\HtmlBlock;
use Psr\Log\LoggerInterface;
use \Twig_Environment;
use Mouf\Html\Renderer\Twig\TwigTemplate;
use Zend\Diactoros\Response\RedirectResponse;

/**
 * This controller generates Cms components
 */
class CmsGeneratorController extends AbstractMoufInstanceController {

    /**
     * The main content block of the page.
     * @var HtmlBlock
     */
    public $content;

    /**
     * @Action
     * @Logged
     */
    public function index($name, $selfedit = "false") {

        $this->initController($name, $selfedit);

        $this->selfedit = $selfedit;
        $this->content->addFile(__DIR__.'/../views/cmsGenerator.php', $this);
        $this->template->toHtml();
    }

    /**
     * This action generates the DAOs and Beans for the TDBM service passed in parameter.
     *
     * @Action
     *
     * @param string $name
     * @param string $componentName
     * @param string $selfedit
     *
     * @return RedirectResponse
     */
    public function componentGenerate($name, $componentName, $selfedit = 'false')
    {
        $this->initController($name, $selfedit);

        if(null == $componentName){
            set_user_message("You must define a component name.", UserMessageInterface::ERROR);
            header('Location: '.ROOT_URL.'cmsadmin/?name='.$name);
        }
        self::sqlGenerate($componentName);

        $uniqueName = "cms-generator-component-".$componentName;

        DatabasePatchInstaller::registerPatch($this->moufManager,
            $uniqueName,
            "Creating the table for the new CMS component : ".$componentName,
            "database/up/".date('YmdHis')."-patch.sql", // SQL patch file, relative to ROOT_PATH
            "database/down/".date('YmdHis')."-patch.sql"); // Optional SQL revert patch file, relative to ROOT_PATH

        $this->moufManager->rewriteMouf();

        $patchService = new InstanceProxy('patchService', $selfedit == 'true');
        $patchService->apply($uniqueName);

        $daonamespace = $this->moufManager->getVariable('tdbmDefaultDaoNamespace');
        $beannamespace = $this->moufManager->getVariable('tdbmDefaultBeanNamespace');
        $daofactoryclassname = $this->moufManager->getVariable('tdbmDefaultDaoFactoryName');
        $daofactoryinstancename = $this->moufManager->getVariable('tdbmDefaultDaoFactoryInstanceName');
        $storeInUtc = $this->moufManager->getVariable('tdbmDefaultStoreInUtc');
        $useCustomComposer = $this->moufManager->getVariable('tdbmDefaultUseCustomComposer');
        $composerFile = $this->moufManager->getVariable('tdbmDefaultComposerFile');

        $tdbmService = new InstanceProxy($name);
        /* @var $tdbmService TDBMService */
        $tables = $tdbmService->generateAllDaosAndBeans($daofactoryclassname, $daonamespace, $beannamespace, $storeInUtc, ($useCustomComposer ? $composerFile : null));

        $this->moufManager->declareComponent($daofactoryinstancename, $daonamespace.'\\Generated\\'.$daofactoryclassname, false, MoufManager::DECLARE_ON_EXIST_KEEP_INCOMING_LINKS);

        $tableToBeanMap = [];

        //$tdbmServiceDescriptor = $moufManager->getInstanceDescriptor('tdbmService');

        foreach ($tables as $table) {
            $daoName = TDBMDaoGenerator::getDaoNameFromTableName($table);

            $instanceName = TDBMDaoGenerator::toVariableName($daoName);
            if (!$this->moufManager->instanceExists($instanceName)) {
                $this->moufManager->declareComponent($instanceName, $daonamespace.'\\'.$daoName);
            }
            $this->moufManager->setParameterViaConstructor($instanceName, 0, $name, 'object');
            $this->moufManager->bindComponentViaSetter($daofactoryinstancename, 'set'.$daoName, $instanceName);

            $tableToBeanMap[$table] = $beannamespace.'\\'.TDBMDaoGenerator::getBeanNameFromTableName($table);
        }
        $tdbmServiceDescriptor = $this->moufManager->getInstanceDescriptor($name);
        $tdbmServiceDescriptor->getSetterProperty('setTableToBeanMap')->setValue($tableToBeanMap);
        $this->moufManager->rewriteMouf();

        $rootPath = realpath(ROOT_PATH.'../../../').'/';

        // Let's insert uses in the Bean
        $beanFileName = $rootPath."src/".$beannamespace."/".ucfirst($componentName)."Bean.php"; // Ex: src/Model/Bean/ComponentnameBean.php

        if(!file_exists($beanFileName)){
            set_user_message("Fail on editing the Bean", UserMessageInterface::ERROR);
            header('Location: '.ROOT_URL.'cmsadmin/?name='.$name);
        }

        $fileContent = file_get_contents($beanFileName);

        $useBaseBean = ucfirst($componentName)."BaseBean;";
        $useBaseBeanLength = strlen($useBaseBean);
        $useBaseBeanPos = strpos($fileContent, $useBaseBean); // Search the position of the "ComponentnameBaseBean" in the fileContent

        $useTraitInterface = "\nuse Mouf\\Cms\\Generator\\Utils\\CmsTrait;\nuse Mouf\\Cms\\Generator\\Utils\\CmsInterface;\n";
        $fileContent = substr_replace($fileContent, $useTraitInterface, $useBaseBeanPos+$useBaseBeanLength, 0); // Insert the string in the fileContent

        $extendsBaseBean = "extends ".ucfirst($componentName)."BaseBean";
        $extendsBaseBeanLength = strlen($extendsBaseBean);
        $extendsBaseBeanPos = strpos($fileContent, $extendsBaseBean); // Search the position of the "extends ComponentnameBaseBean" in the fileContent

        $implementsCmsInsterFace = " implements CmsInterface {\n    use CmsTrait;\n\n";
        $fileContent = substr_replace($fileContent, $implementsCmsInsterFace, $extendsBaseBeanPos+$extendsBaseBeanLength, -1); // Replace the string in the fileContent

        file_put_contents($beanFileName, $fileContent);

        // Let's create a method in the Dao
        $daoFileName = $rootPath."src/".$daonamespace."/".ucfirst($componentName)."Dao.php"; // Ex: src/Model/Dao/ComponentnameDao.php

        if(!file_exists($daoFileName)){
            set_user_message("Fail on editing the Dao", UserMessageInterface::ERROR);
            header('Location: '.ROOT_URL.'cmsadmin/?name='.$name);
        }

        $fileContent = file_get_contents($daoFileName);

        $extendsBaseDao = "extends ".ucfirst($componentName)."BaseDao";
        $extendsBaseDaoLength = strlen($extendsBaseDao);
        $extendsBaseDaoPos = strpos($fileContent, $extendsBaseDao);

        $getBySlug = "\n{
    /**
     * @param string \$slug
     * @return \\".$beannamespace."\\".ucfirst($componentName)."Bean
     */
    public function getBySlug(\$slug) {
        return \$this->findOne('slug = :slug', array('slug' => addslashes(\$slug)));
    }
}";
        $fileContent = substr_replace($fileContent, $getBySlug, $extendsBaseDaoPos+$extendsBaseDaoLength, -1); // Insert the string in the fileContent

        file_put_contents($daoFileName, $fileContent);

        $namespace = $this->moufManager->getVariable('splashDefaultControllersNamespace');
        $viewDir = "vendor/mouf/cmsgenerator/src/views/";
        $controllerGenerator  = new CMSControllerGeneratorService();

        $actions = [
            [
                'view' => 'twig',
                'url' => strtolower($componentName).'/list',
                'anyMethod' => false,
                'getMethod' => true,
                'postMethod' => false,
                'putMethod' => false,
                'deleteMethod' => false,
                'method' => 'displayFrontList',
                'code' =>
                    '
        $items = $this->daoFactory->get'.ucfirst($componentName).'Dao()->findAll();
        $itemUrl = "'.strtolower($componentName).'/";
        $itemUrlEdit = "'.strtolower($componentName).'/admin/edit?id=";
        $this->content->addHtmlElement(new TwigTemplate($this->twig, "'.$viewDir.'front/list.twig'.'",
            array(
                "items"=>$items,
                "itemUrl"=>$itemUrl,
                "itemUrlEdit"=>$itemUrlEdit
            )));
        '
            ],
            [
                'view' => 'twig',
                'url' => strtolower($componentName).'/admin/list',
                'anyMethod' => false,
                'getMethod' => true,
                'postMethod' => false,
                'putMethod' => false,
                'deleteMethod' => false,
                'method' => 'displayBackList',
                'code' =>
                    '
        $items = $this->daoFactory->get'.ucfirst($componentName).'Dao()->findAll();
        $itemUrl = "'.strtolower($componentName).'/";
        $itemUrlEdit = "'.strtolower($componentName).'/admin/edit?id=";
        $this->content->addHtmlElement(new TwigTemplate($this->twig, "'.$viewDir.'back/list.twig'.'",
            array(
                "items"=>$items,
                "itemUrl"=>$itemUrl,
                "itemUrlEdit"=>$itemUrlEdit
            )));
        '
            ],
            [
                'view' => 'twig',
                'url' => strtolower($componentName).'/admin/edit',
                'parameters' => [
                    [
                        'optionnal' => true,
                        'defaultValue' => null,
                        'type' => 'int|null',
                        'name' => 'id',
                    ]
                ],
                'anyMethod' => false,
                'getMethod' => true,
                'postMethod' => false,
                'putMethod' => false,
                'deleteMethod' => false,
                'method' => 'editItem',
                'code' =>
                    '
        $item = null;
        $itemUrl = "'.strtolower($componentName).'/";
        $itemSaveUrl = "'.strtolower($componentName).'/admin/save";
        if(isset($id)){
            $item = $this->daoFactory->get'.ucfirst($componentName).'Dao()->getById($id);
        }
        $this->content->addHtmlElement(new TwigTemplate($this->twig, "'.$viewDir.'back/edit.twig'.'",
            array(
                "item"=>$item,
                "itemUrl"=>$itemUrl,
                "itemSaveUrl"=>$itemSaveUrl
            )));
        '
            ],
            [
                'view' => 'twig',
                'url' => strtolower($componentName).'/{slug}',
                'parameters' => [
                    [
                        'optionnal' => false,
                        'type' => 'string',
                        'name' => 'slug',
                    ]
                ],
                'anyMethod' => false,
                'getMethod' => true,
                'postMethod' => false,
                'putMethod' => false,
                'deleteMethod' => false,
                'method' => 'displayItem',
                'code' =>
                    '
        $item = $this->daoFactory->get'.ucfirst($componentName).'Dao()->getBySlug($slug);
        $this->content->addHtmlElement(new TwigTemplate($this->twig, "'.$viewDir.'front/item.twig'.'", array("item"=>$item)));
        '
            ],
            [
                'view' => 'redirect',
                'url' => strtolower($componentName).'/admin/save',
                'parameters' => [
                    [
                        'optionnal' => false,
                        'type' => 'string',
                        'name' => 'title',
                    ],
                    [
                        'optionnal' => true,
                        'type' => 'int|null',
                        'name' => 'id',
                        'defaultValue' => null
                    ],
                    [
                        'optionnal' => true,
                        'type' => 'string',
                        'name' => 'shortText',
                        'defaultValue' => ''
                    ],
                    [
                        'optionnal' => true,
                        'type' => 'string',
                        'name' => 'content',
                        'defaultValue' => ''
                    ],
                    [
                        'optionnal' => true,
                        'type' => 'array',
                        'name' => 'vignette',
                        'defaultValue' => array()
                    ],
                ],
                'anyMethod' => false,
                'getMethod' => false,
                'postMethod' => true,
                'putMethod' => false,
                'deleteMethod' => false,
                'method' => 'save',
                'redirect' => strtolower($componentName).'/admin/list',
                'code' =>
                    '
        if(isset($id)) {
            $item = $this->daoFactory->get'.ucfirst($componentName).'Dao()->getById($id);
        } else {
            $item = new '.ucfirst($componentName).'Bean();
        }
        $slug = $item->slugify($title);
        $item->setTitle($title);
        $item->setSlug($slug);
        $item->setShortText($shortText);
        $item->setContent($content);
        $this->daoFactory->get'.ucfirst($componentName).'Dao()->save($item);

        $uploadDir = ROOT_PATH."public/media/'.strtolower($componentName).'/".$item->getId()."/";
        $uploadUrl = ROOT_URL."public/media/'.strtolower($componentName).'/".$item->getId()."/";

        if(isset($_FILES)){
            if(isset($_FILES["vignette"]) && $_FILES["vignette"]["error"] != 4){
                $docName = $item->saveFile($_FILES["vignette"], $uploadDir, array("jpg", "png", "jpeg", "bmp"));

                $item->setImage($uploadUrl.$docName);
                $this->daoFactory->get'.ucfirst($componentName).'Dao()->save($item);
            }
        }

        set_user_message("Item successfully created !",UserMessageInterface::SUCCESS);
        '
            ],
        ];
        $controllerGenerator->generate($this->moufManager, $componentName.'Controller', strtolower($componentName).'Controller', $namespace, $componentName, false, true, true, $actions);

        set_user_message("Component successfully created.", UserMessageInterface::SUCCESS);
        header('Location: '.ROOT_URL.'cmsadmin/?name='.$name);
    }

    /**
     * This action generates the DAOs and Beans for the TDBM service passed in parameterd
     *
     * @param string $componentName
     * @return string
     */
    public function sqlGenerate($componentName)
    {
        $rootPath = realpath(ROOT_PATH.'../../../').'/';

        $upSqlFileName = "database/up/".date('YmdHis')."-patch.sql";
        $downSqlFileName = "database/down/".date('YmdHis')."-patch.sql";

        $baseDirUpSqlFile = dirname($rootPath.$upSqlFileName);
        $baseDirDownSqlFile = dirname($rootPath.$downSqlFileName);

        $upSql = "CREATE TABLE IF NOT EXISTS `".htmlspecialchars($componentName)."` (
                          `id` int(11) NOT NULL AUTO_INCREMENT,
                          `title` varchar(255) NOT NULL DEFAULT '',
                          `slug` varchar(255) NOT NULL DEFAULT '',
                          `short_text` text  DEFAULT NULL,
                          `content` text  DEFAULT NULL,
                          `image` text  DEFAULT NULL,
                          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          PRIMARY KEY (`id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        $downSql = "DROP TABLE IF EXISTS `".htmlspecialchars($componentName)."`";

        // Let's create the directory
        if (!file_exists($baseDirUpSqlFile)) {
            $old = umask(0);
            $result = @mkdir($baseDirUpSqlFile, 0775, true);
            umask($old);
            if (!$result) {
                set_user_message("Sorry, impossible to create directory '".plainstring_to_htmlprotected($baseDirUpSqlFile)."'. Please check directory permissions.");
                //header('Location: .?name='.urlencode($name).'&selfedit='.urlencode($selfedit).($patchInstanceName ? '&patchInstanceName='.$patchInstanceName : ''));

                return;
            }
        }

        if (!is_writable($baseDirUpSqlFile)) {
            set_user_message("Sorry, directory '".plainstring_to_htmlprotected($baseDirUpSqlFile)."' is not writable. Please check directory permissions.");
            //header('Location: .?name='.urlencode($name).'&selfedit='.urlencode($selfedit).($patchInstanceName ? '&patchInstanceName='.$patchInstanceName : ''));

            return;
        }

        // Let's create the directory
        if (!file_exists($baseDirDownSqlFile)) {
            $old = umask(0);
            $result = @mkdir($baseDirDownSqlFile, 0775, true);
            umask($old);
            if (!$result) {
                set_user_message("Sorry, impossible to create directory '".plainstring_to_htmlprotected($baseDirDownSqlFile)."'. Please check directory permissions.");
                //header('Location: .?name='.urlencode($name).'&selfedit='.urlencode($selfedit).($patchInstanceName ? '&patchInstanceName='.$patchInstanceName : ''));

                return;
            }
        }

        if (!is_writable($baseDirDownSqlFile)) {
            set_user_message("Sorry, directory '".plainstring_to_htmlprotected($baseDirDownSqlFile)."' is not writable. Please check directory permissions.");
            //header('Location: .?name='.urlencode($name).'&selfedit='.urlencode($selfedit).($patchInstanceName ? '&patchInstanceName='.$patchInstanceName : ''));

            return;
        }

        file_put_contents($rootPath.$upSqlFileName, $upSql);
        // Chmod may fail if the file does not belong to the Apache user.
        @chmod($rootPath.$upSqlFileName, 0664);

        file_put_contents($rootPath.$downSqlFileName, $downSql);
        // Chmod may fail if the file does not belong to the Apache user.
        @chmod($rootPath.$downSqlFileName, 0664);

    }
}
