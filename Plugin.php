<?php
/**
 * Turnstile 人机验证插件
 * 
 * @package Turnstile
 * @author NKXingXh & XiNian-dada
 * @version 1.3.2
 * @link https://blog.nkxingxh.top/
 * @link https://leeinx.com/
 */

use Typecho\Common;
use Utils\PasswordHash;

class Turnstile_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 是否启用救援模式
     * 启用后，将跳过登录验证，适用于无法通过验证时临时排查问题
     */
    private const RESCUE_MODE = false;

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Feedback')->comment = array(__CLASS__, 'verifyTurnstile_comment');
        Typecho_Plugin::factory('Widget_Archive')->header = array(__CLASS__, 'header');
        Typecho_Plugin::factory('admin/footer.php')->end = array(__CLASS__, 'output_login');
        Typecho_Plugin::factory('Widget_User')->hashValidate = array(__CLASS__, 'verifyTurnstile_login');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
    }

    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $siteKeyDescription = _t("请在 <a href='https://dash.cloudflare.com/'>Cloudflare Turnstile</a> 中创建站点");
        $siteKey = new Typecho_Widget_Helper_Form_Element_Text('siteKey', NULL, '', _t('Site Key'), $siteKeyDescription);
        $secretKey = new Typecho_Widget_Helper_Form_Element_Text('secretKey', NULL, '', _t('Serect Key'), _t(''));
        $enableActions = new Typecho_Widget_Helper_Form_Element_Checkbox('enableActions', [
            "login" => _t('登录'),
            "comment" => _t('评论')
        ], array(), _t('在哪些地方启用验证'), _t('给评论启用验证后需要修改主题模板, 查看<a href="https://blog.nkxingxh.top/archives/240/">教程</a>'));
        $theme = new Typecho_Widget_Helper_Form_Element_Radio('theme', array(
            'auto' => '自动',
            'light' => '亮色',
            'dark' => '暗色'
        ), 'auto', _t('主题'), _t(''));
        $strictMode = new Typecho_Widget_Helper_Form_Element_Radio('strictMode', array(
            'enable' => '启用',
            'disable' => '禁用'
        ), 'disable', _t('严格模式'), _t('启用后将会严格判断提交评论与验证时使用的IP是否一致'));
        $pjaxSupport = new Typecho_Widget_Helper_Form_Element_Radio('pjaxSupport', array(
            'enable' => '启用',
            'disable' => '禁用'
        ), 'disable', _t('PJAX 支持'), _t('启用后将会在 &lt;header&gt; 中加载验证 JS 并改变部分逻辑以尽量适配 PJAX'));
        $useCurl = new Typecho_Widget_Helper_Form_Element_Radio('useCurl', array(
            'enable' => '启用',
            'disable' => '禁用'
        ), 'disable', _t('使用 cURL'), _t('(建议启用) 启用后将会使用 cURL 发送请求，但是需要 PHP 的 cURL 拓展。默认使用 file_get_contents 函数'));
        $curlVerifyCert = new Typecho_Widget_Helper_Form_Element_Radio('curlVerifyCert', array(
            'enable' => '启用',
            'disable' => '禁用'
        ), 'disable', _t('cURL 证书验证'), _t('启用后将会校验 HTTPS 证书，此选项仅在使用 cURL 时有效'));
        $autoRefresh = new Typecho_Widget_Helper_Form_Element_Radio('autoRefresh', array(
            'enable' => '启用',
            'disable' => '禁用'
        ), 'enable', _t('自动刷新验证码'), _t('启用后会在验证码超时时自动刷新，而不是显示错误消息'));

        $form->addInput($siteKey);
        $form->addInput($secretKey);
        $form->addInput($enableActions);
        $form->addInput($theme);
        $form->addInput($strictMode);
        $form->addInput($pjaxSupport);
        $form->addInput($useCurl);
        $form->addInput($curlVerifyCert);
        $form->addInput($autoRefresh);
    }

    public static function header()
    {
        if (Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->pjaxSupport == 'enable') {
            echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
            if (Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->jQueryImport == 'enable') {
                echo '<script src="https://lf9-cdn-tos.bytecdntp.com/cdn/expire-1-M/jquery/3.6.0/jquery.min.js" type="application/javascript"></script>';
            }
        }
    }

    /**
     * 展示验证码
     */
    public static function output()
    {
        if (!in_array('comment', Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->enableActions)) {
            return;
        }
        $siteKey = Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->siteKey;
        $autoRefresh = Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->autoRefresh;
        if ($siteKey != "") {
            $theme = Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->theme;
            $action = 'comment';
            // 添加隐藏字段存储令牌
            echo '<input type="hidden" id="cf-turnstile-token" name="cf-turnstile-response" value="">';
            
            if (Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->pjaxSupport == 'enable') {
                echo <<<EOL
<script id="typecho-turnstile-script">
document.addEventListener('DOMContentLoaded', function() {
    var turnstileContainer = document.getElementById('cf-turnstile');
    if (turnstileContainer) {
        turnstileContainer.innerHTML = '';
        console.log(' %c Turnstile for Typecho %c https://blog.nkxingxh.top/archives/240/', 'color:white;background:#31655f;padding:5px 0', 'color:#eee;background:#444;padding:5px');
        if (typeof turnstile !== 'undefined') {
            try {
                var widgetId;
                function renderTurnstile() {
                    // 清除容器内容
                    turnstileContainer.innerHTML = '';
                    widgetId = turnstile.render('#cf-turnstile', {
                        sitekey: '$siteKey',
                        theme: '$theme',
                        action: '$action',
                        callback: function(token) {
                            console.log('Challenge Success');
                            // 存储令牌到隐藏字段
                            document.getElementById('cf-turnstile-token').value = token;
                            // 添加验证成功标记
                            document.getElementById('cf-turnstile').setAttribute('data-verified', 'true');
                        },
                        'error-callback': function(error) {
                            console.error('Turnstile Error:', error);
                            // 仅当之前未验证成功时才处理错误
                            if (document.getElementById('cf-turnstile').getAttribute('data-verified') !== 'true') {
                                if ('$autoRefresh' === 'enable' && error === '300030') {
                                    // 自动刷新验证码
                                    console.log('验证码超时，自动刷新中...');
                                    if (widgetId) {
                                        turnstile.remove(widgetId);
                                    }
                                    setTimeout(renderTurnstile, 1000);
                                } else {
                                    document.getElementById('cf-turnstile').innerHTML = '验证失败，请刷新页面重试';
                                }
                            }
                        },
                        'expired-callback': function() {
                            // 令牌过期处理
                            document.getElementById('cf-turnstile-token').value = '';
                            document.getElementById('cf-turnstile').setAttribute('data-verified', 'false');
                            console.log('Turnstile token expired');
                        }
                    });
                }
                renderTurnstile();
            } catch (e) {
                console.error('Turnstile render error:', e);
                turnstileContainer.innerHTML = '验证组件初始化失败';
            }
        } else {
            turnstileContainer.innerHTML = '验证组件 API 未加载';
        }
    }
});
</script>
EOL;
            } else {
                echo <<<EOL
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=onloadTurnstileCallback" async defer></script>
<script id="typecho-turnstile-script">
// 存储验证状态
var turnstileVerified = false;
var turnstileWidgetId;

window.onloadTurnstileCallback = function() {
    console.log('Turnstile API loaded for comment');
    var turnstileContainer = document.getElementById('cf-turnstile');
    if (turnstileContainer) {
        turnstileContainer.innerHTML = '';
        console.log(' %c Turnstile for Typecho %c https://blog.nkxingxh.top/archives/240/', 'color:white;background:#31655f;padding:5px 0', 'color:#eee;background:#444;padding:5px');
        try {
            function renderTurnstile() {
                turnstileContainer.innerHTML = '';
                turnstileWidgetId = turnstile.render('#cf-turnstile', {
                    sitekey: '$siteKey',
                    theme: '$theme',
                    action: '$action',
                    callback: function(token) {
                        console.log('Challenge Success');
                        // 存储令牌到隐藏字段
                        document.getElementById('cf-turnstile-token').value = token;
                        turnstileVerified = true;
                        // 添加验证成功标记
                        document.getElementById('cf-turnstile').setAttribute('data-verified', 'true');
                    },
                    'error-callback': function(error) {
                        console.error('Turnstile Error:', error);
                        // 仅当之前未验证成功时才处理错误
                        if (!turnstileVerified) {
                            if ('$autoRefresh' === 'enable' && error === '300030') {
                                // 自动刷新验证码
                                console.log('验证码超时，自动刷新中...');
                                if (turnstileWidgetId) {
                                    turnstile.remove(turnstileWidgetId);
                                }
                                setTimeout(renderTurnstile, 1000);
                            } else {
                                turnstileContainer.innerHTML = '验证失败，请刷新页面重试';
                            }
                        }
                    },
                    'expired-callback': function() {
                        // 令牌过期处理
                        document.getElementById('cf-turnstile-token').value = '';
                        turnstileVerified = false;
                        document.getElementById('cf-turnstile').setAttribute('data-verified', 'false');
                        console.log('Turnstile token expired');
                    }
                });
            }
            renderTurnstile();
        } catch (e) {
            console.error('Turnstile render error:', e);
            turnstileContainer.innerHTML = '验证组件初始化失败';
        }
    }
};

// 如果 DOM 已准备好且 API 已加载，直接初始化
if (document.readyState !== 'loading') {
    setTimeout(function() {
        if (typeof turnstile !== 'undefined') {
            window.onloadTurnstileCallback();
        }
    }, 100);
} else {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            if (typeof turnstile !== 'undefined') {
                window.onloadTurnstileCallback();
            }
        }, 100);
    });
}

// 超时检查
setTimeout(function() {
    var turnstileEl = document.getElementById('cf-turnstile');
    if (turnstileEl && (turnstileEl.innerHTML === '正在加载验证组件' || turnstileEl.innerHTML === '')) {
        if (typeof turnstile !== 'undefined') {
            console.log('Retry initializing comment Turnstile');
            window.onloadTurnstileCallback();
        } else {
            turnstileEl.innerHTML = '验证组件 API 加载超时，请刷新页面重试';
        }
    }
}, 5000);

// 防止点击评论框等元素导致验证重置
document.addEventListener('focus', function(e) {
    if (e.target.matches('textarea, input[type="text"], input[type="email"], input[type="url"]') && turnstileVerified && typeof turnstile !== 'undefined') {
        // 验证已完成，不需要做任何操作
        console.log('Form field focused, verification already completed');
    }
}, true);
</script>
EOL;
            }
            echo <<<EOL
<div id="cf-turnstile" data-verified="false">正在加载验证组件</div>
EOL;
        } else {
            throw new Typecho_Widget_Exception(_t('请先设置 Turnstile Site Key!'));
        }
    }

    public static function output_login()
    {
        // 判断是否登录页面
        $currentRequestUrl = Typecho_Widget::widget('Widget_Options')->request->getRequestUrl();
        if (!stripos($currentRequestUrl, 'login.php') || !in_array('login', Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->enableActions)) {
            return;
        }
        $siteKey = Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->siteKey;
        $autoRefresh = Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->autoRefresh;
        if ($siteKey != "") {
            $theme = Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->theme;
            $action = 'login';
            echo <<<EOF
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=onloadTurnstileCallback" async defer></script>
<script>
var loginTurnstileWidgetId;

function initLoginTurnstile() {
    console.log('Initializing Login Turnstile...');
    // 查找密码字段
    var passwordField = document.getElementById('password');
    if (!passwordField) {
        console.log('Password field not found, retrying...');
        setTimeout(initLoginTurnstile, 500);
        return;
    }
    // 添加验证组件容器（如果不存在）
    var turnstileContainer = document.getElementById('cf-turnstile');
    if (!turnstileContainer) {
        turnstileContainer = document.createElement('div');
        turnstileContainer.id = 'cf-turnstile';
        turnstileContainer.innerHTML = '正在加载验证组件';
        // 在密码字段的父元素后插入
        var passwordParent = passwordField.parentElement;
        if (passwordParent.nextSibling) {
            passwordParent.parentNode.insertBefore(turnstileContainer, passwordParent.nextSibling);
        } else {
            passwordParent.parentNode.appendChild(turnstileContainer);
        }
    }
    // 等待 Turnstile API 加载
    if (typeof turnstile === 'undefined') {
        console.log('Waiting for Turnstile API...');
        return;
    }
    // 清空容器内容
    turnstileContainer.innerHTML = '';
    // 输出版本信息
    console.log(' %c Turnstile for Typecho %c https://blog.nkxingxh.top/archives/240/', 'color:white;background:#31655f;padding:5px 0', 'color:#eee;background:#444;padding:5px');
    try {
        function renderLoginTurnstile() {
            turnstileContainer.innerHTML = '';
            loginTurnstileWidgetId = turnstile.render('#cf-turnstile', {
                sitekey: '$siteKey',
                theme: '$theme',
                action: '$action',
                callback: function(token) {
                    console.log('Challenge Success');
                },
                'error-callback': function(error) {
                    console.error('Turnstile Error:', error);
                    if ('$autoRefresh' === 'enable' && error === '300030') {
                        // 自动刷新验证码
                        console.log('验证码超时，自动刷新中...');
                        if (loginTurnstileWidgetId) {
                            turnstile.remove(loginTurnstileWidgetId);
                        }
                        setTimeout(renderLoginTurnstile, 1000);
                    } else {
                        document.getElementById('cf-turnstile').innerHTML = '验证失败，请刷新页面重试';
                    }
                }
            });
            console.log('Turnstile rendered successfully');
        }
        renderLoginTurnstile();
    } catch (e) {
        console.error('Turnstile render error:', e);
        document.getElementById('cf-turnstile').innerHTML = '验证组件初始化失败: ' + e.message;
    }
}

// 设置 Turnstile API 回调
window.onloadTurnstileCallback = function() {
    console.log('Turnstile API loaded via callback');
    initLoginTurnstile();
};

// 页面加载完成后的处理
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLoginTurnstile);
} else {
    // DOM 已准备好，直接初始化
    initLoginTurnstile();
}

// 设置超时检查，防止回调未触发
setTimeout(function() {
    var turnstileEl = document.getElementById('cf-turnstile');
    if (turnstileEl && (turnstileEl.innerHTML === '正在加载验证组件' || turnstileEl.innerHTML === '')) {
        if (typeof turnstile !== 'undefined') {
            console.log('Retry initializing Turnstile');
            initLoginTurnstile();
        } else {
            turnstileEl.innerHTML = '验证组件 API 加载超时，请刷新页面重试';
        }
    }
}, 5000);
</script>
EOF;
        } else {
            throw new Typecho_Widget_Exception(_t('请先设置 Turnstile Site Key!'));
        }
    }

    public static function verifyTurnstile_comment($comments, $obj)
    {
        $userObj = $obj->widget('Widget_User');
        if (($userObj->hasLogin() && $userObj->pass('administrator', true)) || !in_array('comment', Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->enableActions)) {
            return $comments;
        } elseif (isset($_POST['cf-turnstile-response'])) {
            //$siteKey = Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->siteKey;
            $secretKey = Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->secretKey;
            $strictMode = Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->strictMode == 'enable';
            if (empty($_POST['cf-turnstile-response'])) {
                throw new Typecho_Widget_Exception(_t('请先完成验证'));
            }
            $resp = self::getTurnstileResult($_POST['cf-turnstile-response'], $secretKey, $strictMode);
            if ($resp['success']) {
                if ($resp['action'] == 'comment') {
                    return $comments;
                } else {
                    throw new Typecho_Widget_Exception(_t(self::getTurnstileResultMsg('场景验证失败')));
                }
            } else {
                throw new Typecho_Widget_Exception(_t(self::getTurnstileResultMsg($resp)));
            }
        } else {
            throw new Typecho_Widget_Exception(_t('加载验证码失败, 请检查你的网络'));
        }
    }

    public static function verifyTurnstile_login($password, $hash)
    {
        $enableTurnstile = in_array('login', Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->enableActions);
        if ($enableTurnstile && !self::RESCUE_MODE) {
            if (isset($_POST['cf-turnstile-response'])) {
                if (empty($_POST['cf-turnstile-response'])) {
                    Typecho_Widget::widget('Widget_Notice')->set(_t('请先完成验证'), 'error');
                    Typecho_Widget::widget('Widget_Options')->response->goBack();
                }
                $secretKey = Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->secretKey;
                $strictMode = Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->strictMode == 'enable';
                $resp = self::getTurnstileResult($_POST['cf-turnstile-response'], $secretKey, $strictMode);
                if ($resp['success']) {
                    if ($resp['action'] != 'login') {
                        self::loginFailed(self::getTurnstileResultMsg('场景验证失败'));
                        return false;
                    }
                    //return true;
                } else {
                    self::loginFailed(self::getTurnstileResultMsg($resp));
                    return false;
                }
            } else {
                self::loginFailed('请等待人机验证加载完成');
                return false;
            }
        }
        /**
         * 参考 /var/Widget/User.php 中的 login 方法
         * 
         * https://github.com/typecho/typecho/blob/master/var/Widget/User.php
         */
        if ('$P$' == substr($hash, 0, 3)) {
            $hasher = new PasswordHash(8, true);
            $hashValidate = $hasher->checkPassword($password, $hash);
        } else {
            $hashValidate = Common::hashValidate($password, $hash);
        }
        return $hashValidate;
    }

    private static function loginFailed($msg)
    {
        Typecho_Widget::widget('Widget_Notice')->set(_t($msg), 'error');
        //Typecho_Widget::widget('Widget_User')->logout();
        Typecho_Widget::widget('Widget_Options')->response->goBack();
    }

    private static function getTurnstileResult($turnstile_response, $secretKey, $strictMode = false)
    {
        $payload = array('secret' => $secretKey, 'response' => $turnstile_response);
        if ($strictMode) $payload['remoteip'] = $_SERVER['REMOTE_ADDR'];
        if (Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->useCurl == 'enable') {
            $response = self::CurlPOST(http_build_query($payload), 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        } else {
            $stream = stream_context_create(array(
                'http' => array(
                    'method' => 'POST',
                    'content' => http_build_query($payload)
                )
            ));
            $response = file_get_contents("https://challenges.cloudflare.com/turnstile/v0/siteverify", false, $stream);
        }
        $response = json_decode($response, true);
        if (empty($response)) {
            throw new Typecho_Widget_Exception(_t('Turnstile 无响应，请检查服务器网络连接'));
        }
        return $response;
    }

    private static function getTurnstileResultMsg($resp)
    {
        if ($resp['success'] == true) {
            return '验证通过';
        } else {
            switch (strtolower($resp['error-codes'][0])) {
                case 'missing-input-response':
                    return '请先完成验证';
                case 'invalid-input-response':
                    return '验证无效或已过期';
                case 'timeout-or-duplicate':
                    return '验证响应已被使用, 请重新验证';
                case 'bad-request':
                    return '照理说不会出现这个问题的, 再试一次?';
                case 'internal-error':
                    return 'Turnstile 验证服务器拉了, 再试一次吧';
                case 'missing-input-secret':
                case 'invalid-input-secret':
                    return '未设置或设置了无效的 secretKey';
                default:
                    return '你食不食人啊? (恼) 如果是的话再试一次?';
            }
        }
    }

    /**
     * 以下部分代码来自 MiraiEz
     * 
     * MiraiEz Copyright (c) 2021-2024 NKXingXh
     * License AGPLv3.0: GNU AGPL Version 3 <https://www.gnu.org/licenses/agpl-3.0.html>
     * This is free software: you are free to change and redistribute it.
     * There is NO WARRANTY, to the extent permitted by law.
     * 
     * Github: https://github.com/nkxingxh/MiraiEz
     */

    private static function CurlPOST($payload, $url, $cookie = null, $referer = null, $header = array(), $setopt = array(), $UserAgent = null, ...$other)
    {
        //$setopt[] = [CURLOPT_POST, 1]; //当设置了 CURLOPT_POSTFIELDS 时, CURLOPT_POST 默认为 1
        //$setopt[] = [CURLOPT_POSTFIELDS, $payload];
        return self::Curl($payload, $url, $cookie, $referer, $header, $setopt, $UserAgent, ...$other);
    }

    private static function Curl($payload, $url, $cookie = null, $referer = null, $header = array(), $setopt = array(), $UserAgent = null, &$curl = null)
    {
        $header = is_array($header) ? $header : array($header);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_USERAGENT, $UserAgent ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36');
        if (!empty($header)) {
            // KV 数组转数字索引
            foreach ($header as $key => $value) {
                if (is_string($key)) {
                    $header[] = "$key: $value";
                    unset($header[$key]);
                }
            }
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        }
        if (!empty($referer)) curl_setopt($curl, CURLOPT_REFERER, $referer);
        if (!empty($cookie)) curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        if (!empty($payload)) curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        // 关闭 SSL
        if (Typecho_Widget::widget('Widget_Options')->plugin('Turnstile')->curlVerifyCert == 'disable') {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }
        // 设置信任证书
        curl_setopt($curl, CURLOPT_CAINFO, __DIR__ . '/../config/curl-ca-bundle.crt');
        // 返回数据不直接显示
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        // 适配 gzip 压缩
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip, deflate');
        if (!empty($setopt) && is_array($setopt)) {
            $n = count($setopt);
            for ($i = 0; $i < $n; $i++) {
                curl_setopt($curl, $setopt[$i][0], $setopt[$i][1]);
            }
        }
        $response = curl_exec($curl);
        // 如果传入了 $curl 参数则不释放
        if (!array_key_exists(7, func_get_args())) {
            curl_close($curl);
        }
        return $response;
    }
}