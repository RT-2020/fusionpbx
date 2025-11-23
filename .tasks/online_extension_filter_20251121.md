# 任务: 增强全呼/急呼功能的在线分机筛选

**任务标识**: online_extension_filter_20251121  
**创建日期**: 2025-11-21  
**功能分支**: `task/online_extension_filter_20251121`

## 需求背景

操作面板的批量呼叫和急呼功能需要增强,确保当勾选"使用全呼"选项时,系统只呼叫当前在线的分机,而不是所有注册的分机,并且排除调度员自身的分机号。

## 研究发现

### 系统架构分析

1. **批量呼叫和急呼按钮位置**:
   - `index.php` L851-853: 批量呼叫按钮 `openBatchCallModal()`
   - `index.php` L857-859: 急呼按钮 `openEmergencyModal()`

2. **会议配置API**:
   - `dispatcher_api.php` L488-601: `get_conferences` 和 `get_emergency_conferences`
   - 已支持在线分机筛选 (L541-570)
   - 使用 FreeSWITCH `show registrations as json` 获取在线分机
   - 对 `all_call` 模式默认启用 `online_only=true`

3. **调度员分机识别**:
   - 通过 `eavesdrop_dest` hidden input (定义在 `resources/content.php`)
   - 也可通过 `dispatcherControl.config.authUser` 获取
   - 在 `index.php` L2244 使用 `$('#eavesdrop_dest').val()` 获取

4. **呼叫流程**:
   ```
   批量呼叫/急呼 
   → openBatchCallModal() / openEmergencyModal()
   → updateBatchCallTargets() / updateEmergencyTargets()
   → $.ajax('dispatcher_api.php?action=get_conferences')
   → 返回会议配置及 participants 列表
   → initiateBatchCall() / initiateEmergencyCall()
   → dispatcherControl.startGroupCallWithExtensions(targets)
   ```

### 当前问题

1. **✗ 未排除调度员自身分机号**:
   - API返回的 `participants` 可能包含调度员自己的分机
   - 可能导致调度员呼叫自己

2. **✗ 无分机状态缓存机制**:
   - 每次请求都实时查询 `show registrations`
   - 高频使用可能影响性能
   - 无法快速响应分机上线/下线状态变化

3. **✗ 界面功能说明不够清晰**:
   - 批量呼叫模态框 (L1023-1054) 未明确说明全呼只呼叫在线分机
   - 急呼模态框 (L1085-1134) 未明确全呼的行为差异
   - 用户可能不清楚"组呼"和"全呼"的区别

### 代码结构分析

#### dispatcher_api.php 关键代码段

**L541-570: 在线分机筛选逻辑**
```php
// 计算在线分机列表(当前域名下的注册用户),默认启用 online_only 逻辑
$online_only = !isset($_GET['online_only']) || $_GET['online_only'] !== 'false';
$online_extensions = [];
try {
    $registrations_json = event_socket::api('show registrations as json');
    $registrations = json_decode($registrations_json, true);
    if (is_array($registrations) && isset($registrations['rows']) && is_array($registrations['rows'])) {
        $domain_name = $_SESSION['domain_name'];
        $online_map = [];
        foreach ($registrations['rows'] as $row) {
            $realm = $row['realm'] ?? ($row['to-host'] ?? '');
            $user  = $row['user'] ?? '';
            if ($user === '') { continue; }
            if ($realm !== '' && strcasecmp($realm, $domain_name) !== 0) { continue; }
            $online_map[$user] = true;
        }
        if (!empty($online_map)) { $online_extensions = array_keys($online_map); }
    }
} catch (Exception $e) { /* 忽略ESL异常 */ }
```

**L564-570: 全呼模式的分机列表返回**
```php
if ($call_mode === 'all_call') {
    // 全呼:默认仅返回当前在线分机;如 online_only=false 则返回所有启用分机
    if ($online_only && !empty($online_extensions)) {
        $participants = array_values(array_intersect($all_extensions, $online_extensions));
    } else {
        $participants = $all_extensions;
    }
}
```

**问题**: 这段代码没有排除调度员自身分机号!

#### index.php 关键代码段

**L2050-2079: 批量呼叫发起逻辑**
```javascript
function initiateBatchCall() {
    var selectedOption = $('#batch-call-target option:selected');
    if (!selectedOption.val()) {
        DispatcherUtils.alert('请选择一个会议', 'warn');
        return;
    }
    
    try {
        var participantsJson = selectedOption.attr('data-participants');
        var targets = JSON.parse(participantsJson);
        
        if (!targets || targets.length === 0) {
            DispatcherUtils.alert('该会议没有配置参与分机', 'warn');
            return;
        }
        
        // 使用 dispatcherControl 发起呼叫
        dispatcherControl.startGroupCallWithExtensions(targets);
        
        closeBatchCallModal();
        $('#group-call-status').show();
        var typeText = ($('#batch-call-type').val() === 'broadcast') ? '全呼' : '组呼';
        $('#group-call-status-text').text(typeText + '进行中...');
        
    } catch (e) {
        console.error('解析会议参与者失败:', e);
        DispatcherUtils.alert('发起呼叫失败', 'error');
    }
}
```

**问题**: 前端接收到的 `targets` 直接使用,未进行二次过滤

## 实施方案 (已采纳方案B)

### 方案B: 前端过滤增强 ✅

**选择理由**:
- 不修改API，影响范围小
- 灵活性高，易于调试
- 功能触发频率不高，无需缓存机制
- 前端可以更精确地获取当前登录调度员的分机

**实施细节**:

#### 1. 批量呼叫功能 (`initiateBatchCall`)
**文件**: `index.php` L2050-2106

**修改内容**:
- 在解析 `participants` 后添加过滤逻辑
- 获取调度员分机号（优先级: `eavesdrop_dest` → `dispatcherControl.config.authUser`）
- 使用 `Array.filter()` 移除调度员分机
- 验证过滤后列表不为空
- 更新状态显示: `'全呼进行中 (呼叫 X 个分机)...'`
- 添加console日志记录排除操作

**代码结构**:
```javascript
function initiateBatchCall() {
    // ... 解析 participants ...
    
    // [新增] 获取调度员分机号
    var operatorExt = $('#eavesdrop_dest').val();
    if (!operatorExt && dispatcherControl && dispatcherControl.config) {
        operatorExt = dispatcherControl.config.authUser;
    }
    
    // [新增] 过滤调度员分机
    if (operatorExt) {
        var originalCount = targets.length;
        targets = targets.filter(ext => ext !== operatorExt);
        if (targets.length < originalCount) {
            console.log('批量呼叫: 已排除调度员自身分机 ' + operatorExt);
        }
    }
    
    // [新增] 验证过滤后的列表
    if (targets.length === 0) {
        DispatcherUtils.alert('过滤后没有可呼叫的分机', 'warn');
        return;
    }
    
    // 发起呼叫
    dispatcherControl.startGroupCallWithExtensions(targets);
    
    // [修改] 状态显示包含数量
    $('#group-call-status-text').text(typeText + '进行中 (呼叫 ' + targets.length + ' 个分机)...');
}
```

#### 2. 急呼功能 (`initiateEmergencyCall`)
**文件**: `index.php` L2215-2350+

**修改内容**:
- **单用户急呼** (L2226-2231): 添加自我检查，禁止对自己发起急呼
- **组呼/全呼** (L2284-2299): 过滤调度员分机
- 添加console日志记录

**代码结构**:
```javascript
function initiateEmergencyCall() {
    var type = $('#emergency-type').val();
    var targets = [];
    
    if (type === 'single') {
        targetExt = $('#emergency-target-ext').val().trim();
        
        // [新增] 单用户自我检查
        var operatorExt = $('#eavesdrop_dest').val() || 
                         (window.dispatcherControl && dispatcherControl.config && dispatcherControl.config.authUser);
        if (operatorExt && targetExt === operatorExt) {
            DispatcherUtils.alert('不能对自己发起急呼', 'warn');
            return;
        }
        
        targets = [targetExt];
    } else {
        // 解析组呼/全呼的 participants ...
    }
    
    // 获取调度员分机
    var dispatcherExt = ...;
    
    // [新增] 组呼/全呼过滤逻辑
    if (type !== 'single' && dispatcherExt) {
        var originalCount = targets.length;
        targets = targets.filter(ext => ext !== dispatcherExt);
        
        if (targets.length < originalCount) {
            console.log('急呼: 已排除调度员自身分机 ' + dispatcherExt);
        }
        
        if (targets.length === 0) {
            DispatcherUtils.alert('过滤后没有可呼叫的分机', 'warn');
            return;
        }
    }
    
    // 发起急呼 ...
}
```

### UI改进

**状态显示增强**:
- 批量呼叫: `"全呼进行中 (呼叫 5 个分机)..."` 或 `"组呼进行中 (呼叫 3 个分机)..."`
- 显示实际呼叫的分机数量，让用户清楚了解呼叫范围

**错误提示**:
- 单用户对自己急呼: `"不能对自己发起急呼"`
- 过滤后无目标: `"过滤后没有可呼叫的分机"`

### 技术实现要点

#### 调度员分机号获取优先级
1. **第一优先**: `$('#eavesdrop_dest').val()` - 页面上显式选择的落地分机
2. **备用方案**: `dispatcherControl.config.authUser` - JsSIP注册的用户名

这种双重获取机制确保了在不同场景下都能正确识别调度员分机。

#### 过滤逻辑的防御性设计
```javascript
// 过滤前验证分机号存在
if (operatorExt) {
    targets = targets.filter(function(ext) {
        return ext !== operatorExt;  // 使用严格比较
    });
}

// 过滤后验证列表不为空
if (targets.length === 0) {
    DispatcherUtils.alert('过滤后没有可呼叫的分机', 'warn');
    return;  // 阻止发起呼叫
}
```

#### 日志记录规范
```javascript
// 只在实际排除时记录日志
if (targets.length < originalCount) {
    console.log('批量呼叫: 已排除调度员自身分机 ' + operatorExt);
}
```

### 未实施的功能

根据用户决策，以下功能**未实施**:
- ❌ 分机状态缓存机制（功能触发频率不高）
- ❌ 额外的功能说明文本（仅显示呼叫数量）
- ❌ 后端API修改（采用前端方案）

---

## 待办事项 (TODO List)

实现此任务所需的完整步骤清单 (暂未规划详细内容)

## 任务进度

### 阶段 1: 研究分析 ✅
- [x] 分析批量呼叫和急呼功能的代码结构
- [x] 理解会议配置API的实现
- [x] 确认在线分机筛选的现有逻辑
- [x] 识别调度员分机号的获取方式
- [x] 创建任务文件和功能分支

### 阶段 2: 方案设计 ⏸️
- [ ] 确定最终实现方案
- [ ] 设计分机状态缓存机制
- [ ] 设计API接口变更 (如需要)
- [ ] 设计UI改进方案

### 阶段 3: 后端实现 ⏸️
- [ ] 待定

### 阶段 4: 前端实现 ⏸️
- [ ] 待定

### 阶段 5: 测试验证 ⏸️
- [ ] 待定

## 技术细节笔记

### 获取在线分机的FreeSWITCH命令

```bash
# 获取所有注册信息 (JSON格式)
show registrations as json

# 返回示例:
{
  "rows": [
    {
      "user": "1001",
      "realm": "example.com",
      ...
    },
    ...
  ]
}
```

### Session中的调度员分机

位置: `$_SESSION['user']['extension'][0]['user']`

### 页面DOM中的调度员分机

```javascript
// Hidden input
var operatorExt = $('#eavesdrop_dest').val();

// JsSIP config (如果已注册)
var dispatcherExt = dispatcherControl.config.authUser;
```

## 参考资料

- 会话记录: Conversation 6b73a7fb (Fix Emergency Call Originate Syntax)
- 会话记录: Conversation 91c33541 (Refactor Conference Call Modes)
- FreeSWITCH Event Socket Library文档

---

**研究阶段完成时间**: 2025-11-21 17:49
**下一步骤**: 等待用户反馈,确定实现方案
