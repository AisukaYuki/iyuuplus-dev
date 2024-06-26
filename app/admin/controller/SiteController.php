<?php

namespace app\admin\controller;

use app\admin\services\site\LayuiTemplate;
use app\common\HasBackupRecovery;
use app\common\HasDelete;
use app\common\HasValidate;
use app\model\Site;
use Ledc\Curl\Curl;
use plugin\admin\app\controller\Crud;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;

/**
 * 站点设置
 */
class SiteController extends Crud
{
    use HasDelete, HasValidate, HasBackupRecovery;

    /**
     * @var Site
     */
    protected $model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct()
    {
        $this->model = new Site;
    }

    /**
     * 浏览
     * @return Response
     */
    public function index(): Response
    {
        return view('site/index');
    }

    /**
     * 查询
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function find(Request $request): Response
    {
        $primary_key = $this->model->getKeyName();
        $id = $request->get($primary_key);
        $model = $this->model->find($id);
        if (!$model) {
            throw new BusinessException('记录不存在', 2);
        }

        return $this->success('ok', $model->toArray());
    }

    /**
     * 插入
     * @param Request $request
     * @return Response
     */
    public function insert(Request $request): Response
    {
        if ($request->method() === 'POST') {
            return $this->success();
        }
        return view('site/insert');
    }

    /**
     * 更新
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function update(Request $request): Response
    {
        if ($request->method() === 'POST') {
            [$id, $data] = $this->updateInput($request);
            Site::backupToJson($this->model);
            $this->doUpdate($id, $data);
            return $this->json(0);
        }

        $form = LayuiTemplate::generate($request->get('site', ''));
        return view('site/update', [
            'html' => $form->html(),
            'js' => $form->js(),
        ]);
    }

    /**
     * 获取合作站点
     * @param Request $request
     * @return Response
     */
    public function getRecommendSites(Request $request): Response
    {
        if (!check_iyuu_token(iyuu_token())) {
            return $this->fail('IYUU_TOKEN格式错误');
        }

        $curl = new Curl();
        $curl->setSslVerify();
        $curl->get(config('iyuu.base_url') . config('iyuu.endpoint.getRecommendSites'));
        $result = $curl->response ? json_decode($curl->response, true) : [];
        $recommend = $result['data']['recommend'] ?? [];

        return $recommend ? $this->success('ok', $recommend) : $this->fail('IYUU服务器无响应');
    }

    /**
     * 合作站绑定
     * @param Request $request
     * @return Response
     */
    public function bind(Request $request): Response
    {
        if ($request->method() === 'POST') {
            try {
                $rule = [
                    'token|IYUU_TOKEN' => 'require|max:60',
                    'id|用户数字ID' => 'require|number',
                    'site|站点' => 'require',
                    'passkey|绑定密钥' => 'require',
                ];
                $data = [
                    'token' => iyuu_token(),
                    'id' => $request->post('id'),
                    'site' => $request->post('site'),
                    'passkey' => sha1($request->post('passkey', '')), // 避免泄露用户密钥passkey
                ];
                $this->validate($data, $rule);

                // 新版验证依赖的sid字段 2024年4月24日
                $data['sid'] = Site::uniqueSite($data['site'])->sid;
                $curl = new Curl();
                $curl->setSslVerify()->setTimeout(5);
                $curl->get(config('iyuu.base_url') . config('iyuu.endpoint.bind'), $data);
                $result = $curl->response ? json_decode($curl->response, true) : [];
                if (empty($result)) {
                    return $this->fail("用户绑定出错，无法访问IYUU接口，请检查本地网络或稍后重试");
                }

                // 响应码200表示请求成功
                $success = $result['data']['success'] ?? false;
                if (200 === $result['ret'] && $success) {
                    return $this->success('绑定成功');
                }

                $code = $result['ret'] ?? 400;
                $msg = $result['msg'] ?? ($rs['data']['errmsg'] ?? 'IYUU服务器无响应，请稍后重试！');
                return $this->fail("绑定失败，code：{$code} msg：{$msg}");
            } catch (Throwable $throwable) {
                return $this->fail($throwable->getMessage());
            }
        }

        return view('site/bind');
    }

    /**
     * 导入旧版配置
     * @param Request $request
     * @return Response
     */
    public function export(Request $request): Response
    {
        return $this->success();
    }

    /**
     * 格式化下拉列表
     * @param $items
     * @return Response
     */
    protected function formatSelect($items): Response
    {
        $simple = (bool)\request()->input('simple', false);
        $value = \request()->input('value', 'site');
        if (!in_array($value, ['id', 'sid', 'site'], true)) {
            return $this->fail('非法value参数');
        }

        $formatted_items = [];
        /** @var Site $item */
        foreach ($items as $item) {
            $more = $simple ? '' : ($item->options ? '' : ' | 未配置') . ($item->disabled ? ' | 禁用' : '');
            $formatted_items[] = [
                'name' => ($item->nickname ?? '') . $item->site . $more,
                'value' => $item->{$value}
            ];
        }
        return $this->success('ok', $formatted_items);
    }

    /**
     * 对用户输入表单过滤
     * @param array $data
     * @return array
     * @throws BusinessException
     */
    protected function inputFilter(array $data): array
    {
        $table = config('plugin.admin.database.connections.mysql.prefix') . $this->model->getTable();
        $allow_column = $this->model->getConnection()->select("desc `$table`");
        if (!$allow_column) {
            throw new BusinessException('表不存在', 2);
        }
        $columns = array_column($allow_column, 'Type', 'Field');
        foreach ($data as $col => $item) {
            if (!isset($columns[$col])) {
                unset($data[$col]);
                continue;
            }
            // 非字符串类型传空则为null
            if ($item === '' && !str_contains(strtolower($columns[$col]), 'varchar') && !str_contains(strtolower($columns[$col]), 'text')) {
                $data[$col] = null;
            }
        }
        if (empty($data['created_at'])) {
            unset($data['created_at']);
        }
        if (empty($data['updated_at'])) {
            unset($data['updated_at']);
        }
        return $data;
    }
}
