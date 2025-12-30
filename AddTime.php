<?php
/*
    @author Buysoso
    2025-12-30

 */

// 引入 WHMCS 初始化文件
if (!file_exists('init.php')) {
    // 尝试在上级目录查找 (兼容通常的 addon 目录结构)
    if (file_exists('../init.php')) {
        require_once '../init.php';
    } elseif (file_exists('../../init.php')) {
        require_once '../../init.php';
    } else {
        // 如果找不到，假设当前目录
        if (file_exists('init.php')) {
            require_once 'init.php';
        } else {
            exit('[ERROR] init.php not found.' . PHP_EOL);
        }
    }
} else {
    require_once 'init.php';
}

// 1. CLI 环境检测
if (PHP_SAPI !== 'cli') {
    exit('[ERROR] This script must be run from the command line.' . PHP_EOL);
}

use WHMCS\Database\Capsule;

// 2. 配置部分
$config = [
    'package_id' => 1,          // 产品/服务 ID
    'reset_traffic' => true,    // 是否重置流量
    'extend_days' => 10,        // 补偿天数
    'dry_run' => false,         // 默认关闭空跑模式
];

// 获取 CLI 参数 (可选覆盖配置)
// 例如: php AddTime.php --package_id=1 --days=10 --dry-run
$args = getopt('', ['package_id::', 'days::', 'reset_traffic::', 'dry_run::']);
if (isset($args['package_id'])) $config['package_id'] = (int)$args['package_id'];
if (isset($args['days'])) $config['extend_days'] = (int)$args['days'];
if (isset($args['reset_traffic'])) $config['reset_traffic'] = ($args['reset_traffic'] !== 'off' && $args['reset_traffic'] !== 'false');

// 增强的 dry_run 检测：优先检查 getopt，如果失败则直接检查 argv
// 这可以防止因环境差异导致的参数解析失败
if (array_key_exists('dry_run', $args)) {
    $val = $args['dry_run'];
    // 如果是 false (bool)，表示参数存在但无值（通常是 flag），应为 true
    if ($val === false) {
        $config['dry_run'] = true;
    } else {
        $config['dry_run'] = ($val !== 'false' && $val !== 'off' && $val !== '0');
    }
} else {
    // 只有在 getopt 没找到时才检查 argv
    // 检查 argv 中是否有 --dry-run (不区分大小写)
    if (isset($argv)) {
        foreach ($argv as $arg) {
            if (stripos($arg, '--dry-run') !== false || stripos($arg, '--dry_run') !== false) {
                $config['dry_run'] = true;
                break;
            }
        }
    }
}

echo '[' . date('Y-m-d H:i:s') . '] [INFO] 开始执行补偿任务...' . PHP_EOL;
if ($config['dry_run']) {
    echo "⚠️  注意: 当前为 Dry Run (空跑) 模式，不会修改任何数据库或调用 API ⚠️" . PHP_EOL;
} else {
    // 警告用户如果他们以为自己在 dry-run
    if (isset($argv) && in_array('--dry-run', $argv)) {
        echo "⚠️  WARNING: '--dry-run' 参数被检测到但在解析时失败。为了安全起见，脚本将强制开启 Dry Run 模式！" . PHP_EOL;
        $config['dry_run'] = true;
    }
}

echo "配置信息: PackageID={$config['package_id']}, 补偿天数={$config['extend_days']}, 重置流量=" . ($config['reset_traffic'] ? 'On' : 'Off') . PHP_EOL;

try {
    // 3. 预先获取管理员用户 (只需一次)
    // 优先获取 ID 最小的管理员
    $adminUser = Capsule::table('tbladmins')->orderBy('id', 'ASC')->value('username');
    if (!$adminUser) {
        throw new Exception("未找到管理员账户，无法执行 API 操作。");
    }

    // 统计总数
    $query = Capsule::table('tblhosting')
        ->where('packageid', $config['package_id'])
        ->where('domainstatus', 'Active')
        ->orderBy('id', 'ASC');
    
    $count = $query->count();
    echo '[' . date('Y-m-d H:i:s') . '] [INFO] 共有 ' . $count . ' 个服务需要补偿' . PHP_EOL;

    if ($count === 0) {
        exit;
    }

    // 4. 使用 Chunk 分批处理 (防止内存溢出)
    $successCount = 0;
    $failCount = 0;

    // 每次处理 100 条
    $query->chunk(100, function ($services) use ($config, $adminUser, &$successCount, &$failCount) {
        foreach ($services as $service) {
            try {
                $nextDueDateStr = $service->nextduedate;
                $nextInvoiceDateStr = $service->nextinvoicedate;

                // 跳过无效日期
                if ($nextDueDateStr === '0000-00-00' || empty($nextDueDateStr)) {
                    continue;
                }

                // 计算新日期
                // 使用 DateTime 对象处理更稳健
                $nextDueDateObj = new DateTime($nextDueDateStr);
                // 如果 nextinvoicedate 无效，则默认使用 nextduedate
                $nextInvoiceDateObj = ($nextInvoiceDateStr && $nextInvoiceDateStr !== '0000-00-00') 
                    ? new DateTime($nextInvoiceDateStr) 
                    : clone $nextDueDateObj;

                $nextDueDateObj->modify("+{$config['extend_days']} days");
                $nextInvoiceDateObj->modify("+{$config['extend_days']} days");

                $newDueDate = $nextDueDateObj->format('Y-m-d');
                $newInvoiceDate = $nextInvoiceDateObj->format('Y-m-d');

                // 执行流量重置
                $trafficResetResult = 'Skipped';
                if ($config['reset_traffic']) {
                    if ($config['dry_run']) {
                         $trafficResetResult = '[Dry Run: Would Reset]';
                    } else {
                        // 改用 ModuleUnsuspend API，这是 WHMCS 官方推荐的解除暂停（并重置流量）的方式
                        // 它会自动调用模块的 Unsuspend 函数
                        $apiResult = localAPI('ModuleUnsuspend', [
                            'serviceid' => $service->id,
                        ], $adminUser);
                        
                        if (isset($apiResult['result'])) {
                            if ($apiResult['result'] === 'success') {
                                $trafficResetResult = 'Success';
                            } else {
                                // 尝试获取更详细的错误信息
                                $msg = isset($apiResult['message']) ? $apiResult['message'] : 'Unknown Error';
                                $trafficResetResult = "Error: $msg";
                            }
                        } else {
                            $trafficResetResult = 'Invalid Response';
                        }
                    }
                }

                // 更新数据库
                if ($config['dry_run']) {
                    // 仅模拟输出
                } else {
                    Capsule::table('tblhosting')
                        ->where('id', $service->id)
                        ->update([
                            'nextduedate' => $newDueDate, 
                            'nextinvoicedate' => $newInvoiceDate
                        ]);
                }

                echo sprintf(
                    "[%s] [%s] ID:%d UserID:%d | Due: %s -> %s | Reset: %s" . PHP_EOL,
                    date('H:i:s'),
                    $config['dry_run'] ? 'DRY-RUN' : 'SUCCESS',
                    $service->id,
                    $service->userid,
                    $nextDueDateStr,
                    $newDueDate,
                    $trafficResetResult
                );
                $successCount++;

            } catch (\Exception $e) {
                echo sprintf(
                    "[%s] [ERROR] ID:%d 处理失败: %s" . PHP_EOL,
                    date('H:i:s'),
                    $service->id,
                    $e->getMessage()
                );
                $failCount++;
            }
        }
    });

    echo '[' . date('Y-m-d H:i:s') . '] [INFO] 任务完成. 成功: ' . $successCount . ', 失败: ' . $failCount . PHP_EOL;
    if ($config['dry_run']) {
        echo "⚠️  注意: 以上为 Dry Run 模拟执行结果，实际数据未修改。如需应用更改，请去掉 --dry-run 参数运行。 ⚠️" . PHP_EOL;
    }

} catch (\Exception $e) {
    echo '[' . date('Y-m-d H:i:s') . '] [FATAL ERROR] ' . $e->getMessage() . PHP_EOL;
}
