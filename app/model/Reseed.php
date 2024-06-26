<?php

namespace app\model;

use app\model\enums\ReseedStatusEnums;
use Illuminate\Database\Eloquent\Builder;
use plugin\admin\app\model\Base;

/**
 * 自动辅种
 * @property integer $reseed_id 主键(主键)
 * @property integer $client_id 客户端ID
 * @property string $site 站点名字
 * @property integer $sid 站点ID
 * @property integer $torrent_id 种子ID
 * @property integer $group_id 种子分组ID
 * @property string $info_hash 种子infohash
 * @property string $directory 目标文件夹
 * @property integer $dispatch_time 调度时间
 * @property integer $status 状态
 * @property string $message 异常信息
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class Reseed extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cn_reseed';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'reseed_id';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>|bool
     */
    protected $guarded = [];

    /**
     * 获取状态枚举
     * @return ReseedStatusEnums
     */
    public function getStatusEnums(): ReseedStatusEnums
    {
        return ReseedStatusEnums::from($this->getAttribute('status'));
    }

    /**
     * 构造器：获取状态eq默认值
     * @param int $sid
     * @return Builder
     */
    public static function getStatusEqDefault(int $sid): Builder
    {
        return static::where('sid', '=', $sid)->where('status', '=', ReseedStatusEnums::Default->value);
    }

    /**
     * 构造器：获取状态等于枚举值
     * @param int $sid
     * @param ReseedStatusEnums $reseedStatusEnums
     * @return Builder
     */
    public static function getStatusEq(int $sid, ReseedStatusEnums $reseedStatusEnums): Builder
    {
        return static::where('sid', '=', $sid)->where('status', '=', $reseedStatusEnums->value);
    }

    /**
     * 依据client_id批量删除数据
     * @param int $client_id
     * @return int
     */
    public static function deleteByClientId(int $client_id): int
    {
        return self::deleteByColumnValue('client_id', $client_id);
    }

    /**
     * 依据sid批量删除数据
     * @param int $sid
     * @return int
     */
    public static function deleteBySid(int $sid): int
    {
        return self::deleteByColumnValue('sid', $sid);
    }

    /**
     * 私有方法，批量删除数据
     * @param string $column
     * @param string $value
     * @return int
     */
    protected static function deleteByColumnValue(string $column, string $value): int
    {
        $count = 0;
        Reseed::where($column, '=', $value)->chunkById(50, function ($reseeds) use (&$count) {
            /** @var Reseed $reseed */
            foreach ($reseeds as $reseed) {
                $reseed->delete();
                $count++;
            }
        });
        return $count;
    }
}
