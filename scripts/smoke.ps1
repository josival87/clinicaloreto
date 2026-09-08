param([string]$BaseUrl='http://localhost:8090')
$ErrorActionPreference='Stop'
$clinicConfig=@{}
Get-Content -LiteralPath (Join-Path $PSScriptRoot '..\.env') | ForEach-Object { if($_ -match '^([^#=]+)=(.*)$') { $clinicConfig[$matches[1]]=$matches[2].Trim('"') } }
$session=New-Object Microsoft.PowerShell.Commands.WebRequestSession
$state=Invoke-RestMethod "$BaseUrl/api/session" -WebSession $session
$headers=@{Accept='application/json';'X-CSRF-TOKEN'=$state.csrf}
$login=Invoke-RestMethod "$BaseUrl/api/login" -Method Post -WebSession $session -Headers $headers -ContentType 'application/json' -Body (@{cpf=$clinicConfig.ADMIN_CPF;password=$clinicConfig.ADMIN_PASSWORD}|ConvertTo-Json)
if($login.user.level -ne 'admin'){throw 'Administrator login failed'}
$headers['X-CSRF-TOKEN']=$login.csrf
$paths=@('/api/session','/api/dashboard','/api/options','/api/clients','/api/doctors','/api/leaders','/api/specialties','/api/users',('/api/appointments?date='+(Get-Date -Format 'yyyy-MM-dd')),('/api/slots?month='+(Get-Date -Format 'yyyy-MM')),'/api/reports/clients','/api/reports/leaders','/api/reports/appointments','/api/reports/map','/api/whatsapp')
foreach($path in $paths) {
    $response=Invoke-WebRequest "$BaseUrl$path" -UseBasicParsing -WebSession $session -Headers $headers
    if($response.StatusCode -ne 200){throw "HTTP check failed: $path"}
    Write-Output "PASS GET $path"
}
try {
    Invoke-RestMethod "$BaseUrl/api/logout" -Method Post -WebSession $session -Headers @{Accept='application/json';'X-CSRF-TOKEN'='invalid-token';'Sec-Fetch-Site'='cross-site'} -ContentType 'application/json' -Body '{}' | Out-Null
    throw 'Invalid CSRF token was accepted'
} catch {
    if([int]$_.Exception.Response.StatusCode -ne 419){throw}
    Write-Output 'PASS CSRF rejection'
}
Invoke-RestMethod "$BaseUrl/api/logout" -Method Post -WebSession $session -Headers $headers -ContentType 'application/json' -Body '{}' | Out-Null
Write-Output 'PASS login and logout'
