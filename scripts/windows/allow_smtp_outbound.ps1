param(
    [int[]]$Ports = @(587),
    [string]$ApachePath = 'C:\xampp\apache\bin\httpd.exe',
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$RulePrefix = 'WebTest SMTP Outbound'
)

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from an elevated PowerShell window.'
}

function Ensure-OutboundRule {
    param(
        [string]$DisplayName,
        [string]$Program,
        [int]$Port
    )

    if (-not (Test-Path $Program)) {
        throw "Program not found: $Program"
    }

    Get-NetFirewallRule -DisplayName $DisplayName -ErrorAction SilentlyContinue |
        Remove-NetFirewallRule -ErrorAction SilentlyContinue | Out-Null

    New-NetFirewallRule `
        -DisplayName $DisplayName `
        -Direction Outbound `
        -Action Allow `
        -Program $Program `
        -Protocol TCP `
        -RemotePort $Port `
        -Profile Any | Out-Null
}

foreach ($port in $Ports) {
    Ensure-OutboundRule -DisplayName "$RulePrefix Apache $port" -Program $ApachePath -Port $port
    Ensure-OutboundRule -DisplayName "$RulePrefix PHP $port" -Program $PhpPath -Port $port
}

Write-Host ''
Write-Host 'Created outbound firewall rules:' -ForegroundColor Green
Get-NetFirewallRule -DisplayName "$RulePrefix*" |
    Get-NetFirewallApplicationFilter |
    Select-Object Program, InstanceID |
    Format-Table -AutoSize

Write-Host ''
Write-Host 'Quick connectivity check:' -ForegroundColor Green
foreach ($port in $Ports) {
    Test-NetConnection smtp.gmail.com -Port $port |
        Select-Object ComputerName, RemotePort, TcpTestSucceeded |
        Format-Table -AutoSize
}
