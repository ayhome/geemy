<?php
namespace Geemy;
class Template
{

    // 模板页面中引入的标签库列表
    protected $tagLib = array();
    // 当前模板文件
    protected $templateFile = '';
    // 模板变量
    public $tVar     = array();
    public $config   = array();
    private $literal = array();
    private $block   = array();

    private $contents = array();

    /**
     * 架构函数
     * @access public
     */
    public function __construct()
    {
        $this->config['cache_path']      = './runtime/tpl/';
        $this->config['template_suffix'] = '.html';
        $this->config['cache_suffix']    = '.php';
        $this->config['tmpl_cache']      = false;
        $this->config['cache_time']      = 0;
        $this->config['taglib_begin']    = $this->stripPreg('<');
        $this->config['taglib_end']      = $this->stripPreg('>');
        $this->config['tmpl_begin']      = $this->stripPreg('{');
        $this->config['tmpl_end']        = $this->stripPreg('}');
        $this->config['default_tmpl']    = 'layout';
        $this->config['layout_item']     = '{__CONTENT__}';
    }

    private function stripPreg($str)
    {
        return str_replace(
            array('{', '}', '(', ')', '|', '[', ']', '-', '+', '*', '.', '^', '?'),
            array('\{', '\}', '\(', '\)', '\|', '\[', '\]', '\-', '\+', '\*', '\.', '\^', '\?'),
            $str);
    }

    // 模板变量获取和设置
    public function get($name)
    {
        if (isset($this->tVar[$name])) {
            return $this->tVar[$name];
        } else {
            return false;
        }

    }

    public function set($name, $value)
    {
        $this->tVar[$name] = $value;
    }

    /**
     * 加载模板
     * @access public
     * @param string $templateFile 模板文件
     * @param array  $templateVar 模板变量
     * @param string $prefix 模板标识前缀
     * @return void
     */
    public function fetch($templateFile, $templateVar, $prefix = '')
    {

      $this->tVar        = $templateVar;
      $templateCacheFile = $this->loadTemplate($templateFile, $prefix);
      if (!$templateCacheFile) return;

      ob_start();
      ob_implicit_flush(0);
      if (!is_null($this->tVar)) {
        extract($this->tVar, EXTR_OVERWRITE);
      }
      include $templateCacheFile;
      $content = ob_get_clean();
      return $content;
    }

    public function fetch2($templateFile = '', $content = '', $prefix = '')
    {
        if (empty($content)) {
            $templateFile = $this->parseTemplate($templateFile);
            // 模板文件不存在直接返回
            if (!is_file($templateFile)) {
                E(L('_TEMPLATE_NOT_EXIST_') . ':' . $templateFile);
            }

        } else {
            defined('THEME_PATH') or define('THEME_PATH', $this->getThemePath());
        }
        // 页面缓存
        ob_start();
        ob_implicit_flush(0);
        if ('php' == strtolower(C('TMPL_ENGINE_TYPE'))) {
            // 使用PHP原生模板
            if (empty($content)) {
                if (isset($this->tVar['templateFile'])) {
                    $__template__ = $templateFile;
                    extract($this->tVar, EXTR_OVERWRITE);
                    include $__template__;
                } else {
                    extract($this->tVar, EXTR_OVERWRITE);
                    include $templateFile;
                }
            } elseif (isset($this->tVar['content'])) {
                $__content__ = $content;
                extract($this->tVar, EXTR_OVERWRITE);
                eval('?>' . $__content__);
            } else {
                extract($this->tVar, EXTR_OVERWRITE);
                eval('?>' . $content);
            }
        } else {
            // 视图解析标签
            $params = array('var' => $this->tVar, 'file' => $templateFile, 'content' => $content, 'prefix' => $prefix);
            Hook::listen('view_parse', $params);
        }
        // 获取并清空缓存
        $content = ob_get_clean();
        
        // 输出模板文件
        return $content;
    }

    /**
     * 加载主模板并缓存
     * @access public
     * @param string $templateFile 模板文件
     * @param string $prefix 模板标识前缀
     * @return string
     * @throws ThinkExecption
     */
    public function loadTemplate($templateFile, $prefix = '')
    {
        if (is_file($templateFile)) {
            $this->templateFile = $templateFile;
            // 读取模板文件内容
            $tmplContent = file_get_contents($templateFile);
        } else {
            $tmplContent = $templateFile;
        }
        if (!$tmplContent ) return;
        // 根据模版文件名定位缓存文件
        $tmplCacheFile = $this->config['cache_path'] . $prefix . md5($templateFile) . $this->config['cache_suffix'];
        // 编译模板内容
        $tmplContent = $this->compiler($tmplContent);
        $this->put($tmplCacheFile, trim($tmplContent), 'tpl');
        return $tmplCacheFile;
    }

    public function put($filename, $content, $type = '')
    {
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (false === file_put_contents($filename, $content)) {
          echo("$filename not found\n");
            // E(L('_STORAGE_WRITE_ERROR_') . ':' . );
        } else {
            $this->contents[$filename] = $content;
            return true;
        }
    }

    /**
     * 编译模板文件内容
     * @access protected
     * @param mixed $tmplContent 模板内容
     * @return string
     */
    protected function compiler($tmplContent)
    {
        //模板解析
        $tmplContent = $this->parse($tmplContent);
        // 还原被替换的Literal标签
        $tmplContent = preg_replace_callback('/<!--###literal(\d+)###-->/is', array($this, 'restoreLiteral'), $tmplContent);
        // 优化生成的php代码
        $tmplContent = str_replace('?><?php', '', $tmplContent);
        // return strip_whitespace($tmplContent);
        return $tmplContent;
    }

    /**
     * 模板解析入口
     * 支持普通标签和TagLib解析 支持自定义标签库
     * @access public
     * @param string $content 要解析的模板内容
     * @return string
     */
    public function parse($content)
    {
        // 内容为空不解析
        if (empty($content)) {
            return '';
        }

        $begin = $this->config['taglib_begin'];
        $end   = $this->config['taglib_end'];
        // 首先替换literal标签内容
        $content = preg_replace_callback('/' . $begin . 'literal' . $end . '(.*?)' . $begin . '\/literal' . $end . '/is', array($this, 'parseLiteral'), $content);

        
        //解析普通模板标签 {$tagName}
        $content = preg_replace_callback('/(' . $this->config['tmpl_begin'] . ')([^\d\w\s' . $this->config['tmpl_begin'] . $this->config['tmpl_end'] . '].+?)(' . $this->config['tmpl_end'] . ')/is', array($this, 'parseTag'), $content);
        return $content;
    }


    /**
     * 分析XML属性
     * @access private
     * @param string $attrs  XML属性字符串
     * @return array
     */
    private function parseXmlAttrs($attrs)
    {
        $xml = '<tpl><tag ' . $attrs . ' /></tpl>';
        $xml = simplexml_load_string($xml);
        if (!$xml) {
            E(L('_XML_TAG_ERROR_'));
        }

        $xml   = (array) ($xml->tag->attributes());
        $array = array_change_key_case($xml['@attributes']);
        return $array;
    }

    /**
     * 替换页面中的literal标签
     * @access private
     * @param string $content  模板内容
     * @return string|false
     */
    private function parseLiteral($content)
    {
        if (is_array($content)) {
            $content = $content[1];
        }

        if (trim($content) == '') {
            return '';
        }

        //$content            =   stripslashes($content);
        $i                 = count($this->literal);
        $parseStr          = "<!--###literal{$i}###-->";
        $this->literal[$i] = $content;
        return $parseStr;
    }

    /**
     * 还原被替换的literal标签
     * @access private
     * @param string $tag  literal标签序号
     * @return string|false
     */
    private function restoreLiteral($tag)
    {
        if (is_array($tag)) {
            $tag = $tag[1];
        }

        // 还原literal标签
        $parseStr = $this->literal[$tag];
        // 销毁literal记录
        unset($this->literal[$tag]);
        return $parseStr;
    }


    /**
     * 解析标签库的标签
     * 需要调用对应的标签库文件解析类
     * @access public
     * @param object $tagLib  标签库对象实例
     * @param string $tag  标签名
     * @param string $attr  标签属性
     * @param string $content  标签内容
     * @return string|false
     */
    public function parseXmlTag($tagLib, $tag, $attr, $content)
    {
        if (ini_get('magic_quotes_sybase')) {
            $attr = str_replace('\"', '\'', $attr);
        }

        $parse   = '_' . $tag;
        $content = trim($content);
        $tags    = $tagLib->parseXmlAttr($attr, $tag);
        return $tagLib->$parse($tags, $content);
    }

    /**
     * 模板标签解析
     * 格式： {TagName:args [|content] }
     * @access public
     * @param string $tagStr 标签内容
     * @return string
     */
    public function parseTag($tagStr)
    {
        if (is_array($tagStr)) {
            $tagStr = $tagStr[2];
        }

        //if (MAGIC_QUOTES_GPC) {
        $tagStr = stripslashes($tagStr);
        //}
        $flag  = substr($tagStr, 0, 1);
        $flag2 = substr($tagStr, 1, 1);
        $name  = substr($tagStr, 1);
        if ('$' == $flag && '.' != $flag2 && '(' != $flag2) {
            //解析模板变量 格式 {$varName}
            return $this->parseVar($name);
        } elseif ('-' == $flag || '+' == $flag) {
            // 输出计算
            return '<?php echo ' . $flag . $name . ';?>';
        } elseif (':' == $flag) {
            // 输出某个函数的结果
            return '<?php echo ' . $name . ';?>';
        } elseif ('~' == $flag) {
            // 执行某个函数
            return '<?php ' . $name . ';?>';
        } elseif (substr($tagStr, 0, 2) == '//' || (substr($tagStr, 0, 2) == '/*' && substr(rtrim($tagStr), -2) == '*/')) {
            //注释标签
            return '';
        }
        // 未识别的标签直接返回
        return C('TMPL_L_DELIM') . $tagStr . C('TMPL_R_DELIM');
    }

    /**
     * 模板变量解析,支持使用函数
     * 格式： {$varname|function1|function2=arg1,arg2}
     * @access public
     * @param string $varStr 变量数据
     * @return string
     */
    public function parseVar($varStr)
    {
        $varStr               = trim($varStr);
        static $_varParseList = array();
        //如果已经解析过该变量字串，则直接返回变量值
        if (isset($_varParseList[$varStr])) {
            return $_varParseList[$varStr];
        }

        $parseStr  = '';
        $varExists = true;
        if (!empty($varStr)) {
            $varArray = explode('|', $varStr);
            //取得变量名称
            $var = array_shift($varArray);
            if ('Think.' == substr($var, 0, 6)) {
                // 所有以Think.打头的以特殊变量对待 无需模板赋值就可以输出
                $name = $this->parseThinkVar($var);
            } elseif (false !== strpos($var, '.')) {
                //支持 {$var.property}
                $vars = explode('.', $var);
                $var  = array_shift($vars);
                switch (strtolower(C('TMPL_VAR_IDENTIFY'))) {
                    case 'array': // 识别为数组
                        $name = '$' . $var;
                        foreach ($vars as $key => $val) {
                            $name .= '["' . $val . '"]';
                        }

                        break;
                    case 'obj': // 识别为对象
                        $name = '$' . $var;
                        foreach ($vars as $key => $val) {
                            $name .= '->' . $val;
                        }

                        break;
                    default: // 自动判断数组或对象 只支持二维
                        $name = 'is_array($' . $var . ')?$' . $var . '["' . $vars[0] . '"]:$' . $var . '->' . $vars[0];
                }
            } elseif (false !== strpos($var, '[')) {
                //支持 {$var['key']} 方式输出数组
                $name = "$" . $var;
                preg_match('/(.+?)\[(.+?)\]/is', $var, $match);
                $var = $match[1];
            } elseif (false !== strpos($var, ':') && false === strpos($var, '(') && false === strpos($var, '::') && false === strpos($var, '?')) {
                //支持 {$var:property} 方式输出对象的属性
                $vars = explode(':', $var);
                $var  = str_replace(':', '->', $var);
                $name = "$" . $var;
                $var  = $vars[0];
            } else {
                $name = "$$var";
            }
            //对变量使用函数
            if (count($varArray) > 0) {
                $name = $this->parseVarFunction($name, $varArray);
            }

            $parseStr = '<?php echo (' . $name . '); ?>';
        }
        $_varParseList[$varStr] = $parseStr;
        return $parseStr;
    }

    /**
     * 对模板变量使用函数
     * 格式 {$varname|function1|function2=arg1,arg2}
     * @access public
     * @param string $name 变量名
     * @param array $varArray  函数列表
     * @return string
     */
    public function parseVarFunction($name, $varArray)
    {
        //对变量使用函数
        $length = count($varArray);
        //取得模板禁止使用函数列表
        $template_deny_funs = explode(',', C('TMPL_DENY_FUNC_LIST'));
        for ($i = 0; $i < $length; $i++) {
            $args = explode('=', $varArray[$i], 2);
            //模板函数过滤
            $fun = trim($args[0]);
            switch ($fun) {
                case 'default': // 特殊模板函数
                    $name = '(isset(' . $name . ') && (' . $name . ' !== ""))?(' . $name . '):' . $args[1];
                    break;
                default: // 通用模板函数
                    if (!in_array($fun, $template_deny_funs)) {
                        if (isset($args[1])) {
                            if (strstr($args[1], '###')) {
                                $args[1] = str_replace('###', $name, $args[1]);
                                $name    = "$fun($args[1])";
                            } else {
                                $name = "$fun($name,$args[1])";
                            }
                        } else if (!empty($args[0])) {
                            $name = "$fun($name)";
                        }
                    }
            }
        }
        return $name;
    }

    /**
     * 特殊模板变量解析
     * 格式 以 $Think. 打头的变量属于特殊模板变量
     * @access public
     * @param string $varStr  变量字符串
     * @return string
     */
    public function parseThinkVar($varStr)
    {
        $vars     = explode('.', $varStr);
        $vars[1]  = strtoupper(trim($vars[1]));
        $parseStr = '';
        if (count($vars) >= 3) {
            $vars[2] = trim($vars[2]);
            switch ($vars[1]) {
                case 'SERVER':
                    $parseStr = '$_SERVER[\'' . strtoupper($vars[2]) . '\']';
                    break;
                case 'GET':
                    $parseStr = '$_GET[\'' . $vars[2] . '\']';
                    break;
                case 'POST':
                    $parseStr = '$_POST[\'' . $vars[2] . '\']';
                    break;
                case 'COOKIE':
                    if (isset($vars[3])) {
                        $parseStr = '$_COOKIE[\'' . $vars[2] . '\'][\'' . $vars[3] . '\']';
                    } else {
                        $parseStr = 'cookie(\'' . $vars[2] . '\')';
                    }
                    break;
                case 'SESSION':
                    if (isset($vars[3])) {
                        $parseStr = '$_SESSION[\'' . $vars[2] . '\'][\'' . $vars[3] . '\']';
                    } else {
                        $parseStr = 'session(\'' . $vars[2] . '\')';
                    }
                    break;
                case 'ENV':
                    $parseStr = '$_ENV[\'' . strtoupper($vars[2]) . '\']';
                    break;
                case 'REQUEST':
                    $parseStr = '$_REQUEST[\'' . $vars[2] . '\']';
                    break;
                case 'CONST':
                    $parseStr = strtoupper($vars[2]);
                    break;
                case 'LANG':
                    $parseStr = 'L("' . $vars[2] . '")';
                    break;
                case 'CONFIG':
                    if (isset($vars[3])) {
                        $vars[2] .= '.' . $vars[3];
                    }
                    $parseStr = 'C("' . $vars[2] . '")';
                    break;
                default:break;
            }
        } else if (count($vars) == 2) {
            switch ($vars[1]) {
                case 'NOW':
                    $parseStr = "date('Y-m-d g:i a',time())";
                    break;
                case 'VERSION':
                    $parseStr = 'THINK_VERSION';
                    break;
                case 'TEMPLATE':
                    $parseStr = "'" . $this->templateFile . "'"; //'C("TEMPLATE_NAME")';
                    break;
                case 'LDELIM':
                    $parseStr = 'C("TMPL_L_DELIM")';
                    break;
                case 'RDELIM':
                    $parseStr = 'C("TMPL_R_DELIM")';
                    break;
                default:
                    if (defined($vars[1])) {
                        $parseStr = $vars[1];
                    }

            }
        }
        return $parseStr;
    }

    /**
     * 加载公共模板并缓存 和当前模板在同一路径，否则使用相对路径
     * @access private
     * @param string $tmplPublicName  公共模板文件名
     * @param array $vars  要传递的变量列表
     * @return string
     */
    private function parseIncludeItem($tmplPublicName, $vars = array(), $extend)
    {
        // 分析模板文件名并读取内容
        $parseStr = $this->parseTemplateName($tmplPublicName);
        // 替换变量
        foreach ($vars as $key => $val) {
            $parseStr = str_replace('[' . $key . ']', $val, $parseStr);
        }
        // 再次对包含文件进行模板分析
        return $this->parseInclude($parseStr, $extend);
    }

    /**
     * 分析加载的模板文件并读取内容 支持多个模板文件读取
     * @access private
     * @param string $tmplPublicName  模板文件名
     * @return string
     */
    private function parseTemplateName($templateName)
    {
        if (substr($templateName, 0, 1) == '$')
        //支持加载变量文件名
        {
            $templateName = $this->get(substr($templateName, 1));
        }

        $array    = explode(',', $templateName);
        $parseStr = '';
        foreach ($array as $templateName) {
            if (empty($templateName)) {
                continue;
            }

            if (false === strpos($templateName, $this->config['template_suffix'])) {
                // 解析规则为 模块@主题/控制器/操作
                $templateName = T($templateName);
            }
            // 获取模板文件内容
            $parseStr .= file_get_contents($templateName);
        }
        return $parseStr;
    }
}
