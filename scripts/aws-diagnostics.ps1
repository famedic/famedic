param(
    [string] $Bucket
)

$ErrorActionPreference = "Stop"

function Write-Section {
    param([string] $Title)

    Write-Host ""
    Write-Host "== $Title =="
}

function Write-Status {
    param(
        [bool] $Ok,
        [string] $Message
    )

    if ($Ok) {
        Write-Host "[OK] $Message"
    } else {
        Write-Host "[FAIL] $Message"
    }
}

function Mask-Value {
    param([AllowNull()][string] $Value)

    if ([string]::IsNullOrWhiteSpace($Value)) {
        return "(vacio)"
    }

    if ($Value.Length -le 8) {
        return ("*" * $Value.Length)
    }

    return $Value.Substring(0, 4) + ("*" * [Math]::Max(4, $Value.Length - 8)) + $Value.Substring($Value.Length - 4)
}

function Sanitize-Output {
    param(
        [AllowNull()][string] $Text,
        [AllowNull()][string] $AccessKey,
        [AllowNull()][string] $SecretKey
    )

    $sanitized = [string] $Text

    if (-not [string]::IsNullOrWhiteSpace($AccessKey)) {
        $sanitized = $sanitized -replace [Regex]::Escape($AccessKey), (Mask-Value $AccessKey)
    }

    if (-not [string]::IsNullOrWhiteSpace($SecretKey)) {
        $sanitized = $sanitized -replace [Regex]::Escape($SecretKey), "(secret enmascarado)"
    }

    $sanitized = $sanitized -replace "AKIA[0-9A-Z]{16}", "AKIA************MASK"

    return $sanitized
}

function Read-DotEnv {
    param([string] $Path)

    $values = @{}

    if (-not (Test-Path -LiteralPath $Path)) {
        return $values
    }

    foreach ($line in Get-Content -LiteralPath $Path) {
        $trimmed = $line.Trim()

        if ($trimmed -eq "" -or $trimmed.StartsWith("#") -or -not $trimmed.Contains("=")) {
            continue
        }

        $parts = $trimmed.Split("=", 2)
        $key = $parts[0].Trim()
        $value = $parts[1].Trim()

        if (
            ($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'"))
        ) {
            $value = $value.Substring(1, $value.Length - 2)
        }

        if ($key -ne "") {
            $values[$key] = $value
        }
    }

    return $values
}

function Get-Setting {
    param(
        [string] $Key,
        [hashtable] $DotEnv,
        [AllowNull()][string] $Default = $null
    )

    $value = [Environment]::GetEnvironmentVariable($Key, "Process")

    if (-not [string]::IsNullOrWhiteSpace($value)) {
        return $value
    }

    if ($DotEnv.ContainsKey($Key)) {
        return $DotEnv[$Key]
    }

    return $Default
}

function Test-Host443 {
    param([string] $HostName)

    $dnsOk = $false
    try {
        [void] [System.Net.Dns]::GetHostAddresses($HostName)
        $dnsOk = $true
    } catch {
        $dnsOk = $false
    }

    Write-Status $dnsOk "DNS $HostName"

    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $async = $client.BeginConnect($HostName, 443, $null, $null)
        $connected = $async.AsyncWaitHandle.WaitOne(8000, $false)

        if ($connected) {
            $client.EndConnect($async)
        }

        Write-Status ($connected -and $client.Connected) "TCP 443 $HostName"
    } catch {
        Write-Status $false "TCP 443 $HostName ($($_.Exception.Message))"
    } finally {
        $client.Close()
    }
}

function Invoke-AwsJson {
    param([string[]] $Arguments)

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"

    try {
        $output = & aws @Arguments --output json 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    return @{
        ExitCode = $exitCode
        Output = ($output -join [Environment]::NewLine)
    }
}

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSCommandPath)
$dotenv = Read-DotEnv -Path (Join-Path $repoRoot ".env")

$region = Get-Setting -Key "AWS_DEFAULT_REGION" -DotEnv $dotenv -Default "us-east-1"
$configuredBucket = Get-Setting -Key "AWS_BUCKET" -DotEnv $dotenv
$accessKey = Get-Setting -Key "AWS_ACCESS_KEY_ID" -DotEnv $dotenv
$secretKey = Get-Setting -Key "AWS_SECRET_ACCESS_KEY" -DotEnv $dotenv

if ([string]::IsNullOrWhiteSpace($Bucket)) {
    $Bucket = $configuredBucket
}

Write-Section "Configuracion detectada"
Write-Status ([bool] (Get-Command aws -ErrorAction SilentlyContinue)) "AWS CLI disponible"
Write-Status (-not [string]::IsNullOrWhiteSpace($region)) "AWS_DEFAULT_REGION=$region"
Write-Status (-not [string]::IsNullOrWhiteSpace($Bucket)) "AWS_BUCKET=$Bucket"
Write-Status (-not [string]::IsNullOrWhiteSpace($accessKey)) ("AWS_ACCESS_KEY_ID=" + (Mask-Value $accessKey))
Write-Status (-not [string]::IsNullOrWhiteSpace($secretKey)) ("AWS_SECRET_ACCESS_KEY=" + $(if ($secretKey) { "presente ($($secretKey.Length) chars)" } else { "(vacio)" }))

Write-Section "Red hacia AWS"
Test-Host443 -HostName "sts.$region.amazonaws.com"
Test-Host443 -HostName "s3.$region.amazonaws.com"

if (-not [string]::IsNullOrWhiteSpace($Bucket)) {
    Test-Host443 -HostName "$Bucket.s3.$region.amazonaws.com"
}

if (-not (Get-Command aws -ErrorAction SilentlyContinue)) {
    Write-Status $false "No se puede continuar: AWS CLI no esta disponible en este shell."
    exit 2
}

if ([string]::IsNullOrWhiteSpace($region) -or [string]::IsNullOrWhiteSpace($accessKey) -or [string]::IsNullOrWhiteSpace($secretKey)) {
    Write-Status $false "No se puede continuar: faltan region, access key o secret key."
    exit 2
}

$env:AWS_ACCESS_KEY_ID = $accessKey
$env:AWS_SECRET_ACCESS_KEY = $secretKey
$env:AWS_DEFAULT_REGION = $region

Write-Section "STS get-caller-identity"
$sts = Invoke-AwsJson -Arguments @("sts", "get-caller-identity")
if ($sts.ExitCode -eq 0) {
    Write-Status $true "Credenciales validas para STS"
    $identity = $sts.Output | ConvertFrom-Json
    Write-Host "Account: $($identity.Account)"
    Write-Host "Arn: $($identity.Arn)"
    Write-Host "UserId: $($identity.UserId)"
} else {
    Write-Status $false "STS rechazo la solicitud"
    Write-Host (Sanitize-Output -Text $sts.Output -AccessKey $accessKey -SecretKey $secretKey)
}

if ([string]::IsNullOrWhiteSpace($Bucket)) {
    Write-Status $false "No hay bucket configurado para probar S3."
    exit 2
}

Write-Section "S3 bucket"
$head = Invoke-AwsJson -Arguments @("s3api", "head-bucket", "--bucket", $Bucket)
if ($head.ExitCode -eq 0) {
    Write-Status $true "head-bucket $Bucket"
} else {
    Write-Status $false "head-bucket $Bucket"
    Write-Host (Sanitize-Output -Text $head.Output -AccessKey $accessKey -SecretKey $secretKey)
}

$list = Invoke-AwsJson -Arguments @("s3api", "list-objects-v2", "--bucket", $Bucket, "--max-keys", "1")
if ($list.ExitCode -eq 0) {
    Write-Status $true "list-objects-v2 permitido"
    $objects = $list.Output | ConvertFrom-Json
    Write-Host "KeyCount: $($objects.KeyCount)"
} else {
    Write-Status $false "list-objects-v2"
    Write-Host (Sanitize-Output -Text $list.Output -AccessKey $accessKey -SecretKey $secretKey)
}

