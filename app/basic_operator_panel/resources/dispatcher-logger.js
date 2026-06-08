/**
 * 调度通话日志和统计
 */
(function(window) {
    'use strict';

    // 通话日志记录器
    function DispatcherLogger() {
        this.logs = [];
        this.statistics = {
            totalCalls: 0,
            trunkCalls: 0,
            groupCalls: 0,
            conferenceCalls: 0,
            totalDuration: 0
        };
        
        this.loadLogs();
        this.loadStatistics();
    }

    // 加载日志
    DispatcherLogger.prototype.loadLogs = function() {
        var saved = localStorage.getItem('dispatcher_call_logs');
        if (saved) {
            try {
                this.logs = JSON.parse(saved);
            } catch (e) {
                console.error('加载日志失败:', e);
                this.logs = [];
            }
        }
    };

    // 保存日志
    DispatcherLogger.prototype.saveLogs = function() {
        // 只保留最近1000条
        if (this.logs.length > 1000) {
            this.logs = this.logs.slice(-1000);
        }
        localStorage.setItem('dispatcher_call_logs', JSON.stringify(this.logs));
    };

    // 加载统计
    DispatcherLogger.prototype.loadStatistics = function() {
        var saved = localStorage.getItem('dispatcher_statistics');
        if (saved) {
            try {
                this.statistics = JSON.parse(saved);
            } catch (e) {
                console.error('加载统计失败:', e);
            }
        }
    };

    // 保存统计
    DispatcherLogger.prototype.saveStatistics = function() {
        localStorage.setItem('dispatcher_statistics', JSON.stringify(this.statistics));
    };

    // 记录通话
    DispatcherLogger.prototype.logCall = function(type, data) {
        var log = {
            id: 'log_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
            type: type, // 'trunk', 'group', 'conference', 'normal'
            timestamp: new Date().toISOString(),
            duration: data.duration || 0,
            participants: data.participants || [],
            fromNumber: data.fromNumber || '',
            toNumber: data.toNumber || '',
            status: data.status || 'completed',
            operator: data.operator || ''
        };

        this.logs.push(log);
        this.saveLogs();

        // 更新统计
        this.updateStatistics(type, log.duration);

        // 发送到服务器
        this.sendLogToServer(log);

        return log;
    };

    // 更新统计
    DispatcherLogger.prototype.updateStatistics = function(type, duration) {
        this.statistics.totalCalls++;
        this.statistics.totalDuration += duration;

        switch (type) {
            case 'trunk':
                this.statistics.trunkCalls++;
                break;
            case 'group':
                this.statistics.groupCalls++;
                break;
            case 'conference':
                this.statistics.conferenceCalls++;
                break;
        }

        this.saveStatistics();
    };

    // 发送日志到服务器
    DispatcherLogger.prototype.sendLogToServer = function(log) {
        $.ajax({
            url: 'dispatcher_api.php?action=save_call_log',
            type: 'POST',
            data: {
                log_type: log.type,
                participants: JSON.stringify(log.participants),
                duration: log.duration,
                start_time: log.timestamp,
                end_time: new Date().toISOString()
            },
            dataType: 'json',
            success: function(response) {
                console.log('日志已保存到服务器');
            },
            error: function(xhr, status, error) {
                console.warn('保存日志到服务器失败:', error);
            }
        });
    };

    // 获取日志
    DispatcherLogger.prototype.getLogs = function(filter) {
        if (!filter) {
            return this.logs;
        }

        return this.logs.filter(function(log) {
            if (filter.type && log.type !== filter.type) {
                return false;
            }
            if (filter.startDate && new Date(log.timestamp) < filter.startDate) {
                return false;
            }
            if (filter.endDate && new Date(log.timestamp) > filter.endDate) {
                return false;
            }
            return true;
        });
    };

    // 获取统计
    DispatcherLogger.prototype.getStatistics = function() {
        return this.statistics;
    };

    DispatcherLogger.prototype.logError = function(type, data){
        try{
            var saved = localStorage.getItem('dispatcher_error_logs')
            var arr = []
            if (saved){ try{ arr = JSON.parse(saved) || [] }catch(e){ arr = [] } }
            var item = { id:'err_'+Date.now()+'_'+Math.random().toString(36).substr(2,9), type:type||'', ts:Date.now(), data:data||{} }
            arr.push(item)
            if (arr.length>500){ arr = arr.slice(-500) }
            localStorage.setItem('dispatcher_error_logs', JSON.stringify(arr))
        }catch(e){}
    }

    DispatcherLogger.prototype.logEvent = function(type, data){
        try{
            var saved = localStorage.getItem('dispatcher_event_logs')
            var arr = []
            if (saved){ try{ arr = JSON.parse(saved) || [] }catch(e){ arr = [] } }
            var item = { id:'evt_'+Date.now()+'_'+Math.random().toString(36).substr(2,9), type:type||'', ts:Date.now(), data:data||{} }
            arr.push(item)
            if (arr.length>500){ arr = arr.slice(-500) }
            localStorage.setItem('dispatcher_event_logs', JSON.stringify(arr))
        }catch(e){}
    }

    // 清空日志
    DispatcherLogger.prototype.clearLogs = function() {
        if (confirm('确定要清空所有日志吗？此操作不可恢复！')) {
            this.logs = [];
            this.saveLogs();
            console.log('日志已清空');
        }
    };

    // 导出日志
    DispatcherLogger.prototype.exportLogs = function(format) {
        format = format || 'json';
        
        var data;
        var filename = 'dispatcher_logs_' + new Date().toISOString().split('T')[0];

        switch (format) {
            case 'json':
                data = JSON.stringify(this.logs, null, 2);
                filename += '.json';
                break;
            
            case 'csv':
                var csv = 'ID,类型,时间,时长(秒),参与者,状态\n';
                this.logs.forEach(function(log) {
                    csv += [
                        log.id,
                        log.type,
                        log.timestamp,
                        log.duration,
                        log.participants.join(';'),
                        log.status
                    ].join(',') + '\n';
                });
                data = csv;
                filename += '.csv';
                break;
            
            default:
                console.error('不支持的导出格式:', format);
                return;
        }

        // 创建下载
        var blob = new Blob([data], { type: 'text/plain;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.click();
        URL.revokeObjectURL(url);
        
        console.log('日志已导出:', filename);
    };

    // 显示日志面板
    DispatcherLogger.prototype.showLogsPanel = function() {
        var logs = this.getLogs();
        var stats = this.getStatistics();

        var html = '<div class="logs-panel">' +
            '<button class="modal-close-btn" onclick="closeLogsPanel()">×</button>' +
            '<h3>📊 通话日志与统计</h3>' +
            '<div class="statistics">' +
            '<h4>统计数据</h4>' +
            '<p>总通话数: ' + stats.totalCalls + '</p>' +
            '<p>中继呼叫: ' + stats.trunkCalls + '</p>' +
            '<p>组呼次数: ' + stats.groupCalls + '</p>' +
            '<p>会议次数: ' + stats.conferenceCalls + '</p>' +
            '<p>总时长: ' + Math.floor(stats.totalDuration / 60) + ' 分钟</p>' +
            '</div>' +
            '<div class="logs-actions">' +
            '<button onclick="dispatcherLogger.exportLogs(\'csv\')">导出 CSV</button>' +
            '<button onclick="dispatcherLogger.exportLogs(\'json\')">导出 JSON</button>' +
            '<button onclick="dispatcherLogger.clearLogs()">清空日志</button>' +
            '<button onclick="closeLogsPanel()">关闭</button>' +
            '</div>' +
            '<div class="logs-list">' +
            '<h4>最近通话记录（最多显示20条）</h4>';

        if (logs.length === 0) {
            html += '<p style="text-align: center; color: #999; padding: 20px;">暂无通话记录</p>';
        } else {
            html += '<table>' +
                '<tr><th>时间</th><th>类型</th><th>时长</th><th>参与者</th><th>状态</th></tr>';

            // 显示最近20条
            var recentLogs = logs.slice(-20).reverse();
            recentLogs.forEach(function(log) {
                var time = new Date(log.timestamp).toLocaleString('zh-CN');
                var duration = log.duration > 0 ? 
                    Math.floor(log.duration / 60) + '分' + (log.duration % 60) + '秒' : 
                    '0秒';
                var typeLabel = {
                    'trunk': '中继',
                    'group': '组呼',
                    'conference': '会议',
                    'normal': '普通'
                }[log.type] || log.type;
                
                var participants = '';
                if (log.participants && log.participants.length > 0) {
                    participants = log.participants.length + '人';
                } else if (log.fromNumber && log.toNumber) {
                    participants = log.fromNumber + ' → ' + log.toNumber;
                }

                html += '<tr>' +
                    '<td>' + time + '</td>' +
                    '<td>' + typeLabel + '</td>' +
                    '<td>' + duration + '</td>' +
                    '<td>' + participants + '</td>' +
                    '<td>' + log.status + '</td>' +
                    '</tr>';
            });

            html += '</table>';
        }

        html += '</div></div>';

        // 显示面板和遮罩
        $('body').append('<div class="modal-overlay show" onclick="closeLogsPanel()"></div>');
        $('#dispatcher-logs-modal').html(html).show();
    };

    // 导出到全局
    window.DispatcherLogger = DispatcherLogger;

})(window);

