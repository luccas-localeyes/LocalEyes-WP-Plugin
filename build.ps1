<#
.SYNOPSIS
    Builds the upload-ready localeyes-wp-plugin.zip and refuses to ship a broken one.

.DESCRIPTION
    Do NOT use Compress-Archive to package this plugin. On PowerShell 5.1 it writes zip entry
    names with backslash separators ("localeyes-wp-plugin\localeyes-nap.php"). Backslash is a
    legal filename character on Linux, so WordPress unpacks a flat pile of oddly named files
    instead of a directory tree, nothing lands at localeyes-wp-plugin/localeyes-nap.php, and
    activation fails with "Plugin file does not exist."

    This script writes entry names explicitly with forward slashes, then verifies the finished
    archive before moving it into place. The zip at the destination is therefore always one
    that passed every check.

.EXAMPLE
    .\build.ps1

.EXAMPLE
    .\build.ps1 -Version 2.6.0
#>
[CmdletBinding()]
param(
    # Plugin folder name inside the zip. WordPress installs to wp-content/plugins/<Slug>/.
    # 'localeyes-wp-plugin' was abandoned because a poisoned leftover directory of that name blocks
    # every upload with "Destination folder already exists". Nothing in the PHP depends on this name.
    #
    # DO NOT CHANGE IT TO SHIP A RELEASE. WordPress identifies an installed plugin by the string
    # "<Slug>/localeyes-nap.php", so a build under a different slug is a DIFFERENT plugin as far as
    # WordPress is concerned: the "Replace current with uploaded" update path never applies, the old
    # copy stays installed and active, and the site ends up with two LocalEyes entries backed by two
    # sets of side effects fighting over the same drop-in and .htaccess blocks.
    #
    # -Slug exists for one job only: breaking the deadlock when a poisoned folder blocks an install
    # and no LocalEyes copy is active to self-heal it. See README, "Destination folder already
    # exists". Clear the stale folder from Configure > Installation, then go back to the real slug.
    [string] $Slug = 'localeyes-plugin',

    # Set the release version. Rewrites all three version strings in localeyes-nap.php before
    # packaging. Omit it to package whatever is on disk; the drift gate below runs either way.
    [string] $Version = '',

    # Defaults to localeyes-plugin-<version>.zip once the version is known, so the file on disk names
    # its own build. The slug INSIDE the zip is unaffected by this and never changes.
    [string] $OutFile = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root       = $PSScriptRoot
$mainFile   = 'localeyes-nap.php'      # the file carrying the Plugin Name: header
$excludeExt = @('.zip', '.docx')
# CLAUDE.md is internal working rules, and .gitignore/.gitattributes describe a repository the
# installed plugin is not part of. None of the three has any meaning on a live site, and shipping the
# first one puts internal notes on every client install.
$excludeLeaf = @('build.ps1', 'AUDIT.md', 'CLAUDE.md', '.gitignore', '.gitattributes')
# Development-only directories that must never reach a live site. The audit harness in tools/ would
# otherwise be packaged and uploaded with the plugin, since the filter below is otherwise
# extension-based and would happily include .php and .ps1 files from anywhere in the tree.
$excludeDirs = @('tools', '.git', '.github')

function Fail-Build([string] $Message) {
    Write-Host "  FAIL  $Message" -ForegroundColor Red
    $script:failures++
}

function Pass-Check([string] $Message) {
    Write-Host "  ok    $Message" -ForegroundColor Green
}

# True when the file sits under one of the excluded top-level directories.
function Test-ExcludedDir([string] $FullName, [string] $Root, [string[]] $Dirs) {
    $rel = $FullName.Substring($Root.Length).TrimStart('\', '/')
    $first = ($rel -split '[\\/]')[0]
    return ($Dirs -contains $first)
}

# --------------------------------------------------------------------------- version

<#
    Three strings in localeyes-nap.php carry the version, and all three must always agree:

      * Version: X            the plugin header, the only one WordPress reads
      LOCALEYES_NAP_VERSION   reported by GET /localeyes/v1/ping
      LOCALEYES_VER           the admin asset cache-buster and the crawler user agent

    This block exists because that version was frozen at '2.5.0-dev' from the baseline commit
    onwards, and nothing ever bumped it. Two consequences, both live on client sites:

      - "Replace current with uploaded" showed "2.5.0-dev -> 2.5.0-dev", so an update could not be
        confirmed and no site could be asked which build it was running.
      - LOCALEYES_VER is appended to every admin CSS and JS enqueue. A constant value means the
        browser cache key never changes, so a client kept running the previous build's admin
        JavaScript against the new build's REST API until they hard-reloaded.

    Bumping by hand across three lines is what drifts, so the build owns it: -Version rewrites all
    three, and a mismatch is a hard failure whether or not -Version was passed.

    LOCALEYES_DB_VER is deliberately NOT touched here. It is the schema revision, gated separately
    for the reason spelled out in localeyes-nap.php, and it is bumped by hand on schema changes.
#>

$mainPath = Join-Path $root $mainFile
if (-not (Test-Path -LiteralPath $mainPath)) { throw "Main plugin file not found: $mainPath" }

# Read as UTF-8 explicitly. NOT Get-Content -Raw: on PowerShell 5.1 that decodes a BOM-less file
# using the system ANSI codepage, so every multi-byte character in the file comes back as mojibake
# and writing it out again as UTF-8 makes the corruption permanent. This file contains '…' and '·'
# in comments, and the first run of this block turned them into 'â€¦' and 'Â·' across 46 places.
# Reading and writing through the same UTF8Encoding is what keeps the rewrite byte-surgical.
#
# Line endings are untouched: the string keeps its CRLFs and WriteAllText emits them verbatim.
$le_utf8  = New-Object System.Text.UTF8Encoding($false)
$mainText = [System.IO.File]::ReadAllText($mainPath, $le_utf8)

# Each pattern must capture: 1 = everything before the value, 2 = the value, 3 = everything after.
#
# Group 3 of the header pattern ends '\r?' on purpose. These files are CRLF and .gitattributes is
# '* -text', so nothing normalizes them. .NET's multiline '$' matches only before '\n', leaving the
# '\r' unconsumed, so '[ \t]*$' after the value matches nothing and the whole gate silently found
# zero version strings. Capturing the '\r' into group 3 also means the rewrite puts it back, rather
# than converting one line of the file to LF.
$verPatterns = [ordered]@{
    'Version: header'       = '(?m)^([ \t]*\*[ \t]*Version:[ \t]*)(\S+)([ \t]*\r?)$'
    'LOCALEYES_NAP_VERSION' = "(?m)^([ \t]*define\('LOCALEYES_NAP_VERSION',[ \t]*')([^']*)('\);)"
    'LOCALEYES_VER'         = "(?m)^([ \t]*define\('LOCALEYES_VER',[ \t]*')([^']*)('\);)"
}

if ($Version -ne '') {
    # Rejected rather than sanitized: the value lands in a plugin header, a zip filename and a URL
    # query string, and a silently mangled version is worse than a refused build.
    if ($Version -notmatch '^[0-9]+\.[0-9]+(\.[0-9]+)?(-[0-9A-Za-z][0-9A-Za-z.]*)?$') {
        throw "-Version '$Version' is not a version. Expected e.g. 2.6.0, 2.6, or 2.6.0-dev."
    }
    foreach ($verName in $verPatterns.Keys) {
        $pat = $verPatterns[$verName]
        if ([regex]::Matches($mainText, $pat).Count -ne 1) {
            throw "Cannot set the version: $verName did not match exactly once in $mainFile."
        }
        # '${1}' single-quoted so PowerShell leaves the regex group reference alone.
        $mainText = [regex]::Replace($mainText, $pat, ('${1}' + $Version + '${3}'))
    }
    # UTF8Encoding($false), never Set-Content -Encoding utf8: on PowerShell 5.1 that writes a BOM,
    # and a BOM before "<?php" breaks WordPress header parsing. Check 4 below would catch it, but
    # only after this script had already corrupted the working tree.
    [System.IO.File]::WriteAllText($mainPath, $mainText, $le_utf8)
    Write-Host "Version set to $Version in $mainFile (3 strings)" -ForegroundColor Cyan
}

# The gate. Runs whether or not -Version was passed, so a hand-edit that touched one line and missed
# the other two cannot be packaged.
$verFound = [ordered]@{}
foreach ($verName in $verPatterns.Keys) {
    $m = [regex]::Matches($mainText, $verPatterns[$verName])
    if ($m.Count -ne 1) {
        throw "$verName did not match exactly once in $mainFile (found $($m.Count)). Cannot verify the version."
    }
    $verFound[$verName] = $m[0].Groups[2].Value.Trim()
}
$verDistinct = @($verFound.Values | Sort-Object -Unique)
if ($verDistinct.Count -ne 1) {
    Write-Host "`nVersion strings disagree in ${mainFile}:" -ForegroundColor Red
    foreach ($verName in $verFound.Keys) { Write-Host ("  {0,-22} {1}" -f $verName, $verFound[$verName]) -ForegroundColor Red }
    Write-Host "`nAll three must match. Set them together with:  .\build.ps1 -Version <x.y.z>`n" -ForegroundColor Red
    exit 1
}
$pkgVersion = $verDistinct[0]
if ($pkgVersion -eq '') { throw "The version in $mainFile is empty." }

if ($OutFile -eq '') { $OutFile = Join-Path $root ("localeyes-plugin-{0}.zip" -f $pkgVersion) }

# --------------------------------------------------------------------------- collect sources

$sources = Get-ChildItem -LiteralPath $root -Recurse -File -Force |
    Where-Object {
        $_.Extension -notin $excludeExt -and
        $_.Name -notin $excludeLeaf -and
        -not (Test-ExcludedDir $_.FullName $root $excludeDirs)
    } |
    Sort-Object FullName

if ($sources.Count -eq 0) { throw "No source files found under $root" }

# relative path -> FileInfo, keyed the way it will appear in the zip (after the $Slug/ prefix)
$byRel = [ordered]@{}
foreach ($f in $sources) {
    $rel = $f.FullName.Substring($root.Length + 1).Replace('\', '/')
    $byRel[$rel] = $f
}

Write-Host "Packaging $($sources.Count) files from $root" -ForegroundColor Cyan
Write-Host "  slug:    $Slug/"
Write-Host "  version: $pkgVersion"

# --------------------------------------------------------------------------- build to a temp zip

$tmpZip = Join-Path ([System.IO.Path]::GetTempPath()) ("localeyes-build-{0}.zip" -f [System.Guid]::NewGuid().ToString('N'))
$tmpDir = Join-Path ([System.IO.Path]::GetTempPath()) ("localeyes-verify-{0}" -f [System.Guid]::NewGuid().ToString('N'))
$script:failures = 0

try {
    $archive = [System.IO.Compression.ZipFile]::Open($tmpZip, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($rel in $byRel.Keys) {
            # Entry name is built by hand. This is the whole point of not using Compress-Archive.
            $entryName = "$Slug/$rel"
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $byRel[$rel].FullName,
                $entryName,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        $archive.Dispose()
    }

    Write-Host "`nVerifying archive" -ForegroundColor Cyan

    $archive = [System.IO.Compression.ZipFile]::OpenRead($tmpZip)
    try {
        $entries = @($archive.Entries)

        # 1. separators: every entry uses '/', none contains '\'
        $backslashed = @($entries | Where-Object { $_.FullName.Contains('\') })
        if ($backslashed.Count -gt 0) {
            Fail-Build "$($backslashed.Count) entry name(s) contain a backslash, e.g. [$($backslashed[0].FullName)]"
        } else {
            Pass-Check "all $($entries.Count) entry names use forward slashes"
        }

        # 2. exactly one top-level segment, and it is $Slug
        $tops = @($entries | ForEach-Object { ($_.FullName -split '/')[0] } | Sort-Object -Unique)
        if ($tops.Count -ne 1 -or $tops[0] -ne $Slug) {
            Fail-Build "expected a single top-level folder '$Slug', found: $($tops -join ', ')"
        } else {
            Pass-Check "single top-level folder '$Slug/'"
        }

        # 3. entry count matches the source set
        if ($entries.Count -ne $sources.Count) {
            Fail-Build "entry count $($entries.Count) does not match source count $($sources.Count)"
        } else {
            Pass-Check "entry count matches source count ($($entries.Count))"
        }

        # 3b. development tooling must never reach a live site. Asserted on the finished archive
        # rather than trusted from the source filter, because that is the thing that would actually
        # ship if the filter were ever wrong.
        $leaked = @($entries | Where-Object {
            $n = $_.FullName
            ($n -match '(^|/)tools/') -or ($n -match '/AUDIT\.md$') -or ($n -match '(^|/)\.git/') -or
            ($n -match '/CLAUDE\.md$') -or ($n -match '/\.gitignore$') -or ($n -match '/\.gitattributes$')
        })
        if ($leaked.Count -gt 0) {
            Fail-Build "development tooling leaked into the package: $(($leaked | Select-Object -First 5 | ForEach-Object { $_.FullName }) -join ', ')"
        } else {
            Pass-Check "no tools/, AUDIT.md or .git content in the package"
        }

        # 4. the main plugin file is present, has no BOM, opens with <?php, and carries the header
        $mainEntry = $entries | Where-Object { $_.FullName -eq "$Slug/$mainFile" }
        if (-not $mainEntry) {
            Fail-Build "$Slug/$mainFile is not in the archive"
        } else {
            $stream = $mainEntry.Open()
            try {
                $ms = New-Object System.IO.MemoryStream
                $stream.CopyTo($ms)
                $bytes = $ms.ToArray()
            } finally { $stream.Dispose() }

            $text = [System.Text.Encoding]::UTF8.GetString($bytes)
            if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
                Fail-Build "$mainFile starts with a UTF-8 BOM (breaks WordPress header parsing)"
            } elseif (-not $text.StartsWith('<?php')) {
                Fail-Build "$mainFile does not start with '<?php'"
            } elseif ($text -notmatch '(?m)^\s*\*?\s*Plugin Name\s*:') {
                Fail-Build "$mainFile has no 'Plugin Name:' header"
            } else {
                # Read back out of the finished archive, then compared to what the gate read off
                # disk. This is what proves the packaged header carries the version this build
                # claims, rather than trusting the rewrite above.
                $ver = if ($text -match '(?m)^\s*\*?\s*Version\s*:\s*(.+?)\s*$') { $Matches[1] } else { '(none)' }
                if ($ver -ne $pkgVersion) {
                    Fail-Build "packaged $mainFile declares Version: $ver but this build is $pkgVersion"
                } elseif ($text -notmatch '(?m)^\s*\*?\s*Update URI\s*:\s*false\s*$') {
                    # Without it, WP 5.8+ matches this plugin against wordpress.org by folder slug
                    # and can offer a stranger's plugin as an update. See the header docblock.
                    Fail-Build "$mainFile has no 'Update URI: false' header"
                } else {
                    Pass-Check "$mainFile readable, no BOM, Plugin Name + Update URI: false present, Version: $ver"
                }
            }
        }
    } finally {
        $archive.Dispose()
    }

    # 5. extract and compare SHA-256 per file. This is the real proof the package matches disk.
    [System.IO.Compression.ZipFile]::ExtractToDirectory($tmpZip, $tmpDir)
    $mismatched = 0
    $missing    = 0
    foreach ($rel in $byRel.Keys) {
        $extracted = Join-Path $tmpDir (Join-Path $Slug ($rel -replace '/', '\'))
        if (-not (Test-Path -LiteralPath $extracted)) { $missing++; Fail-Build "not extractable to a real path: $rel"; continue }
        $a = (Get-FileHash -LiteralPath $byRel[$rel].FullName -Algorithm SHA256).Hash
        $b = (Get-FileHash -LiteralPath $extracted           -Algorithm SHA256).Hash
        if ($a -ne $b) { $mismatched++; Fail-Build "content differs after round-trip: $rel" }
    }
    if ($missing -eq 0 -and $mismatched -eq 0) {
        Pass-Check "all $($byRel.Count) files round-trip to a real directory tree with matching SHA-256"
    }

    # --------------------------------------------------------------------------- ship or refuse

    if ($script:failures -gt 0) {
        Write-Host "`nBUILD FAILED: $($script:failures) check(s) failed. $OutFile was left untouched." -ForegroundColor Red
        exit 1
    }

    Move-Item -LiteralPath $tmpZip -Destination $OutFile -Force
    $size = (Get-Item -LiteralPath $OutFile).Length
    Write-Host "`nBUILD OK  $pkgVersion  ->  $OutFile  ($([math]::Round($size / 1KB, 1)) KB, $($sources.Count) files)" -ForegroundColor Green
    Write-Host "Upload via Plugins > Add New > Upload Plugin."
    Write-Host "Updating an existing site: upload this zip and choose 'Replace current with uploaded'."
    Write-Host "Do not deactivate or delete first - settings, tables and snapshots survive the replace."
    exit 0

} finally {
    if (Test-Path -LiteralPath $tmpDir) { Remove-Item -LiteralPath $tmpDir -Recurse -Force -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $tmpZip) { Remove-Item -LiteralPath $tmpZip -Force -ErrorAction SilentlyContinue }
}
