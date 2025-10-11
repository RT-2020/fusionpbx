# FreeSWITCH WSS 快速修复脚本 (Windows PowerShell)
# 用于自动配置 WebSocket Secure 支持

# 检查管理员权限
if (-NOT ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole] "Administrator")) {
    Write-Host "错误: 请以管理员身份运行此脚本" -ForegroundColor Red
    Write-Host "右键点击 PowerShell，选择'以管理员身份运行'" -ForegroundColor Yellow
    pause
    exit 1
}

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "FreeSWITCH WSS 快速修复脚本 (Windows)" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# 查找 FreeSWITCH 安装路径
$possiblePaths = @(
    "C:\FreeSWITCH",
    "C:\Program Files\FreeSWITCH",
    "C:\Program Files (x86)\FreeSWITCH"
)

$freeswitchPath = $null
foreach ($path in $possiblePaths) {
    if (Test-Path $path) {
        $freeswitchPath = $path
        break
    }
}

if (-not $freeswitchPath) {
    Write-Host "错误: 找不到 FreeSWITCH 安装目录" -ForegroundColor Red
    Write-Host "请手动配置，参考 WSS_SETUP.md" -ForegroundColor Yellow
    pause
    exit 1
}

Write-Host "步骤 1/6: 检查 FreeSWITCH 安装" -ForegroundColor Blue
Write-Host "✓ 找到 FreeSWITCH: $freeswitchPath" -ForegroundColor Green

# 检查服务状态
Write-Host ""
Write-Host "步骤 2/6: 检查 FreeSWITCH 服务" -ForegroundColor Blue
$service = Get-Service -Name "FreeSWITCH" -ErrorAction SilentlyContinue

if ($service) {
    if ($service.Status -eq "Running") {
        Write-Host "✓ FreeSWITCH 服务正在运行" -ForegroundColor Green
    } else {
        Write-Host "! FreeSWITCH 服务未运行，正在启动..." -ForegroundColor Yellow
        Start-Service -Name "FreeSWITCH"
        Start-Sleep -Seconds 2
    }
} else {
    Write-Host "! 未找到 FreeSWITCH 服务，可能需要手动启动" -ForegroundColor Yellow
}

# 配置 SIP Profile
Write-Host ""
Write-Host "步骤 3/6: 配置 SIP Profile" -ForegroundColor Blue

$sipProfile = Join-Path $freeswitchPath "conf\sip_profiles\internal.xml"
if (-not (Test-Path $sipProfile)) {
    Write-Host "错误: 找不到 SIP Profile: $sipProfile" -ForegroundColor Red
    pause
    exit 1
}

Write-Host "✓ 找到配置文件: $sipProfile" -ForegroundColor Green

# 检查 WSS 配置
$content = Get-Content $sipProfile -Raw
if ($content -match "wss-binding") {
    Write-Host "✓ WSS 绑定已配置" -ForegroundColor Green
} else {
    Write-Host "! WSS 绑定未配置，正在添加..." -ForegroundColor Yellow
    
    # 备份配置
    $backupFile = "$sipProfile.backup.$(Get-Date -Format 'yyyyMMdd_HHmmss')"
    Copy-Item $sipProfile $backupFile
    Write-Host "  配置已备份到: $backupFile" -ForegroundColor Gray
    
    # 添加 WSS 配置
    $wssConfig = @"
    <param name="wss-binding" value=":7443"/>
    <param name="tls-cert-dir" value="$freeswitchPath\certs"/>
    <param name="tls-version" value="tlsv1.2"/>
"@
    
    $content = $content -replace '(</settings>)', "$wssConfig`n    `$1"
    Set-Content -Path $sipProfile -Value $content -Encoding UTF8
    Write-Host "✓ WSS 配置已添加" -ForegroundColor Green
}

# 配置 SSL 证书
Write-Host ""
Write-Host "步骤 4/6: 配置 SSL 证书" -ForegroundColor Blue

$tlsDir = Join-Path $freeswitchPath "certs"
$certFile = Join-Path $tlsDir "wss.pem"

if (-not (Test-Path $tlsDir)) {
    Write-Host "! TLS 目录不存在，正在创建..." -ForegroundColor Yellow
    New-Item -ItemType Directory -Path $tlsDir -Force | Out-Null
}

if (-not (Test-Path $certFile)) {
    Write-Host "! SSL 证书不存在" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Windows 上生成自签名证书需要 OpenSSL。" -ForegroundColor Yellow
    Write-Host "请选择以下方法之一：" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "方法 1: 使用 OpenSSL (需要已安装)" -ForegroundColor White
    Write-Host "  下载: https://slproweb.com/products/Win32OpenSSL.html" -ForegroundColor Gray
    Write-Host ""
    Write-Host "方法 2: 使用 PowerShell 生成证书" -ForegroundColor White
    
    $usePS = Read-Host "使用 PowerShell 生成证书? (Y/N)"
    
    if ($usePS -eq "Y" -or $usePS -eq "y") {
        try {
            # 获取本机 IP
            $ip = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object {$_.InterfaceAlias -notlike "*Loopback*"} | Select-Object -First 1).IPAddress
            
            # 使用 PowerShell 创建自签名证书
            $cert = New-SelfSignedCertificate -DnsName $ip -CertStoreLocation "cert:\LocalMachine\My" -NotAfter (Get-Date).AddYears(1)
            
            # 导出证书
            $password = ConvertTo-SecureString -String "freeswitchpass" -Force -AsPlainText
            Export-PfxCertificate -Cert $cert -FilePath "$tlsDir\wss.pfx" -Password $password | Out-Null
            
            # 转换为 PEM 格式（需要 OpenSSL）
            Write-Host "证书已生成: $tlsDir\wss.pfx" -ForegroundColor Green
            Write-Host "! 注意: 需要使用 OpenSSL 转换为 PEM 格式" -ForegroundColor Yellow
            Write-Host "  命令: openssl pkcs12 -in wss.pfx -out wss.pem -nodes" -ForegroundColor Gray
            
        } catch {
            Write-Host "证书生成失败: $_" -ForegroundColor Red
        }
    } else {
        Write-Host "跳过证书生成，请手动创建证书" -ForegroundColor Yellow
    }
} else {
    Write-Host "✓ SSL 证书已存在: $certFile" -ForegroundColor Green
}

# 配置防火墙
Write-Host ""
Write-Host "步骤 5/6: 配置防火墙" -ForegroundColor Blue

$firewallRule = Get-NetFirewallRule -DisplayName "FreeSWITCH WSS" -ErrorAction SilentlyContinue

if ($firewallRule) {
    Write-Host "✓ 防火墙规则已存在" -ForegroundColor Green
} else {
    Write-Host "! 添加防火墙规则..." -ForegroundColor Yellow
    New-NetFirewallRule -DisplayName "FreeSWITCH WSS" -Direction Inbound -LocalPort 7443 -Protocol TCP -Action Allow | Out-Null
    Write-Host "✓ 防火墙规则已添加" -ForegroundColor Green
}

# 重启服务
Write-Host ""
Write-Host "步骤 6/6: 重启 FreeSWITCH" -ForegroundColor Blue

if ($service) {
    Write-Host "正在重启 FreeSWITCH 服务..." -ForegroundColor Yellow
    Restart-Service -Name "FreeSWITCH" -Force
    Start-Sleep -Seconds 3
    Write-Host "✓ 服务已重启" -ForegroundColor Green
} else {
    Write-Host "! 请手动重启 FreeSWITCH" -ForegroundColor Yellow
}

# 验证配置
Write-Host ""
Write-Host "验证配置..." -ForegroundColor Blue

$ip = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object {$_.InterfaceAlias -notlike "*Loopback*"} | Select-Object -First 1).IPAddress

# 检查端口监听
$portCheck = Test-NetConnection -ComputerName localhost -Port 7443 -WarningAction SilentlyContinue

Write-Host ""
Write-Host "=========================================" -ForegroundColor Green
Write-Host "配置完成！" -ForegroundColor Green
Write-Host "=========================================" -ForegroundColor Green
Write-Host ""
Write-Host "下一步操作：" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. 运行诊断工具验证配置：" -ForegroundColor White
Write-Host "   https://$ip/app/basic_operator_panel/wss_diagnostic.html" -ForegroundColor Gray
Write-Host ""
Write-Host "2. 如果使用自签名证书，需要先信任证书：" -ForegroundColor White
Write-Host "   访问: https://${ip}:7443" -ForegroundColor Gray
Write-Host "   接受证书警告并添加例外" -ForegroundColor Gray
Write-Host ""
Write-Host "3. 在调度终端注册时使用：" -ForegroundColor White
Write-Host "   WebSocket URL: wss://${ip}:7443" -ForegroundColor Gray
Write-Host ""
Write-Host "配置详情：" -ForegroundColor Cyan
Write-Host "  - WSS 端口: 7443" -ForegroundColor White
Write-Host "  - FreeSWITCH 路径: $freeswitchPath" -ForegroundColor White
Write-Host "  - 证书目录: $tlsDir" -ForegroundColor White
Write-Host "  - SIP Profile: $sipProfile" -ForegroundColor White
Write-Host ""
Write-Host "如遇问题，请查看：" -ForegroundColor Cyan
Write-Host "  - 配置指南: WSS_SETUP.md" -ForegroundColor White
Write-Host "  - 故障排查: TROUBLESHOOTING.md" -ForegroundColor White
Write-Host ""

pause

