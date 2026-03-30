param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$capstoneDir = Join-Path $repoRoot 'docs\capstone'
$sourcePath = Join-Path $capstoneDir 'BugCatcher_Capstone_Source.md'
$erdSvgPath = Join-Path $capstoneDir 'BugCatcher_Full_ERD.svg'
$erdPngPath = Join-Path $capstoneDir 'BugCatcher_Full_ERD.png'
$finalDocxPath = Join-Path $capstoneDir 'Capstone_Project_Guidelines_For_IT.docx'
$referenceDocxPath = Join-Path $capstoneDir 'Capstone_Project_Guidelines_For_IT_reference.docx'

function Escape-XmlText {
    param([string]$Text)
    if ($null -eq $Text) {
        return ''
    }

    return [System.Security.SecurityElement]::Escape($Text)
}

function Extract-SectPrXml {
    param([string]$DocumentXml)

    $match = [regex]::Match($DocumentXml, '<w:sectPr[\s\S]*?</w:sectPr>')
    if ($match.Success) {
        return $match.Value
    }

    return '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
}

function Get-ParagraphXml {
    param(
        [string]$Text,
        [string]$Style = '',
        [string]$Align = '',
        [switch]$PageBreakBefore
    )

    $escaped = Escape-XmlText $Text
    $props = New-Object System.Collections.Generic.List[string]
    if ($Style -ne '') {
        $props.Add("<w:pStyle w:val=""$Style""/>")
    }
    if ($Align -ne '') {
        $props.Add("<w:jc w:val=""$Align""/>")
    }
    if ($PageBreakBefore) {
        $props.Add('<w:pageBreakBefore/>')
    }

    $propXml = if ($props.Count -gt 0) { "<w:pPr>$($props -join '')</w:pPr>" } else { '' }
    return "<w:p>$propXml<w:r><w:t xml:space=""preserve"">$escaped</w:t></w:r></w:p>"
}

function Get-BlankParagraphXml {
    return '<w:p><w:r><w:t xml:space="preserve"></w:t></w:r></w:p>'
}

function Get-PageBreakXml {
    return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
}

function Get-ImageParagraphXml {
    param(
        [string]$RelationshipId,
        [string]$FileName,
        [long]$WidthEmu,
        [long]$HeightEmu,
        [int]$DocPrId
    )

    return @"
<w:p>
  <w:pPr>
    <w:jc w:val="center"/>
  </w:pPr>
  <w:r>
    <w:drawing>
      <wp:inline distT="0" distB="0" distL="0" distR="0">
        <wp:extent cx="$WidthEmu" cy="$HeightEmu"/>
        <wp:docPr id="$DocPrId" name="$([System.IO.Path]::GetFileName($FileName))"/>
        <wp:cNvGraphicFramePr>
          <a:graphicFrameLocks noChangeAspect="1"/>
        </wp:cNvGraphicFramePr>
        <a:graphic>
          <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
            <pic:pic>
              <pic:nvPicPr>
                <pic:cNvPr id="$DocPrId" name="$([System.IO.Path]::GetFileName($FileName))"/>
                <pic:cNvPicPr/>
              </pic:nvPicPr>
              <pic:blipFill>
                <a:blip r:embed="$RelationshipId"/>
                <a:stretch><a:fillRect/></a:stretch>
              </pic:blipFill>
              <pic:spPr>
                <a:xfrm>
                  <a:off x="0" y="0"/>
                  <a:ext cx="$WidthEmu" cy="$HeightEmu"/>
                </a:xfrm>
                <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
              </pic:spPr>
            </pic:pic>
          </a:graphicData>
        </a:graphic>
      </wp:inline>
    </w:drawing>
  </w:r>
</w:p>
"@
}

function Parse-ImageDirective {
    param([string]$Line)

    $payload = $Line.Substring(8).Trim()
    $parts = $payload.Split('|')
    return [pscustomobject]@{
        FileName = $parts[0].Trim()
        Caption = if ($parts.Count -gt 1) { $parts[1].Trim() } else { '' }
        WidthInches = if ($parts.Count -gt 2) { [double]::Parse($parts[2].Trim(), [System.Globalization.CultureInfo]::InvariantCulture) } else { 6.5 }
    }
}

function Read-SourceHeadings {
    param([string[]]$Lines)

    $headings = New-Object System.Collections.Generic.List[object]
    foreach ($line in $Lines) {
        if ($line -match '^(#{1,3})\s+(.+)$') {
            $level = $matches[1].Length
            $text = $matches[2].Trim()
            $headings.Add([pscustomobject]@{ Level = $level; Text = $text })
        }
    }

    return $headings
}

function Build-DocumentBodyXml {
    param(
        [string[]]$Lines,
        [System.Collections.Generic.List[object]]$Headings,
        [string]$ImageRelationshipId
    )

    $parts = New-Object System.Collections.Generic.List[string]
    $imageDocPrId = 900

    foreach ($line in $Lines) {
        if ($line -eq '') {
            $parts.Add((Get-BlankParagraphXml))
            continue
        }

        if ($line.StartsWith(':::pagebreak')) {
            $parts.Add((Get-PageBreakXml))
            continue
        }

        if ($line.StartsWith(':::title ')) {
            $parts.Add((Get-ParagraphXml -Text $line.Substring(9).Trim() -Style 'Title' -Align 'center'))
            continue
        }

        if ($line.StartsWith(':::subtitle ')) {
            $parts.Add((Get-ParagraphXml -Text $line.Substring(12).Trim() -Style 'Subtitle' -Align 'center'))
            continue
        }

        if ($line.StartsWith(':::center')) {
            $text = $line.Substring(9).Trim()
            $parts.Add((Get-ParagraphXml -Text $text -Align 'center'))
            continue
        }

        if ($line -eq ':::toc') {
            foreach ($heading in $Headings) {
                $prefix = switch ($heading.Level) {
                    1 { '' }
                    2 { '    ' }
                    default { '        ' }
                }
                $parts.Add((Get-ParagraphXml -Text ($prefix + $heading.Text)))
            }
            continue
        }

        if ($line.StartsWith(':::image ')) {
            $image = Parse-ImageDirective -Line $line
            $targetFile = Join-Path $capstoneDir $image.FileName
            if ((-not (Test-Path $targetFile)) -and $image.FileName.ToLowerInvariant().EndsWith('.png')) {
                $svgFallback = [System.IO.Path]::ChangeExtension($targetFile, '.svg')
                if (Test-Path $svgFallback) {
                    $targetFile = $svgFallback
                    $image.FileName = [System.IO.Path]::GetFileName($svgFallback)
                }
            }
            if (-not (Test-Path $targetFile)) {
                throw "Image file not found: $targetFile"
            }

            if ($image.FileName.ToLowerInvariant().EndsWith('.png')) {
                Add-Type -AssemblyName System.Drawing
                $bitmap = [System.Drawing.Image]::FromFile($targetFile)
                try {
                    $ratio = $bitmap.Height / $bitmap.Width
                } finally {
                    $bitmap.Dispose()
                }
            } else {
                $svgText = Get-Content -Raw $targetFile
                $viewBoxMatch = [regex]::Match($svgText, 'viewBox="[\d\.\-]+\s+[\d\.\-]+\s+([\d\.\-]+)\s+([\d\.\-]+)"')
                if (-not $viewBoxMatch.Success) {
                    throw "SVG viewBox not found for $targetFile"
                }
                $ratio = [double]$viewBoxMatch.Groups[2].Value / [double]$viewBoxMatch.Groups[1].Value
            }

            $widthEmu = [long]($image.WidthInches * 914400)
            $heightEmu = [long]($widthEmu * $ratio)
            $imageDocPrId += 1
            $parts.Add((Get-ImageParagraphXml -RelationshipId $ImageRelationshipId -FileName $image.FileName -WidthEmu $widthEmu -HeightEmu $heightEmu -DocPrId $imageDocPrId))
            if ($image.Caption -ne '') {
                $parts.Add((Get-ParagraphXml -Text $image.Caption -Align 'center'))
            }
            continue
        }

        if ($line -match '^(#{1,3})\s+(.+)$') {
            $level = $matches[1].Length
            $style = switch ($level) {
                1 { 'Heading1' }
                2 { 'Heading2' }
                default { 'Heading3' }
            }
            $parts.Add((Get-ParagraphXml -Text $matches[2].Trim() -Style $style))
            continue
        }

        if ($line.StartsWith('- ')) {
            $parts.Add((Get-ParagraphXml -Text $line))
            continue
        }

        $parts.Add((Get-ParagraphXml -Text $line))
    }

    return ($parts -join "`n")
}

function Replace-ZipEntry {
    param(
        [System.IO.Compression.ZipArchive]$Archive,
        [string]$EntryName,
        [byte[]]$Bytes
    )

    $existing = $Archive.GetEntry($EntryName)
    if ($existing) {
        $existing.Delete()
    }

    $entry = $Archive.CreateEntry($EntryName)
    $stream = $entry.Open()
    try {
        $stream.Write($Bytes, 0, $Bytes.Length)
    } finally {
        $stream.Dispose()
    }
}

function Upsert-Relationship {
    param(
        [string]$Xml,
        [string]$RelationshipId,
        [string]$Target
    )

    $newRelationship = "<Relationship Id=""$RelationshipId"" Type=""http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"" Target=""$Target""/>"
    if ($Xml -match "Id=""$RelationshipId""") {
        return [regex]::Replace($Xml, "<Relationship Id=""$RelationshipId""[^>]*/>", [System.Text.RegularExpressions.MatchEvaluator]{ param($m) $newRelationship })
    }

    return $Xml -replace '</Relationships>', "$newRelationship</Relationships>"
}

function Upsert-ContentType {
    param(
        [string]$Xml,
        [string]$Extension,
        [string]$ContentType
    )

    if ($Xml -match "Extension=""$Extension""") {
        return $Xml
    }

    $snippet = "<Default Extension=""$Extension"" ContentType=""$ContentType""/>"
    return $Xml -replace '</Types>', "$snippet</Types>"
}

function Write-ConsolidatedErdSvg {
    param([string]$OutputPath)

    $nodes = @(
        @{Id='users'; Title='users'; X=30; Y=90; W=250; H=118; Lines=@('PK id','username','email','role')},
        @{Id='password_reset_requests'; Title='password_reset_requests'; X=30; Y=235; W=250; H=100; Lines=@('PK id','FK user_id','otp + expires','verified_at / used_at')},
        @{Id='organizations'; Title='organizations'; X=320; Y=90; W=250; H=100; Lines=@('PK id','name','FK owner_id','created_at')},
        @{Id='org_members'; Title='org_members'; X=320; Y=220; W=250; H=100; Lines=@('PK org_id + user_id','FK org_id','FK user_id','role')},
        @{Id='labels'; Title='labels'; X=320; Y=350; W=250; H=82; Lines=@('PK id','name','color')},
        @{Id='projects'; Title='projects'; X=610; Y=90; W=250; H=118; Lines=@('PK id','FK org_id','name / code','status','FK created_by / updated_by')},
        @{Id='issues'; Title='issues'; X=610; Y=240; W=250; H=136; Lines=@('PK id','FK author_id','FK org_id','FK project_id','workflow_status','multiple assignee ids')},
        @{Id='issue_labels'; Title='issue_labels'; X=900; Y=250; W=220; H=82; Lines=@('FK issue_id','FK label_id')},
        @{Id='issue_attachments'; Title='issue_attachments'; X=900; Y=350; W=220; H=82; Lines=@('PK id','FK issue_id','file_path')},
        @{Id='contact'; Title='contact'; X=900; Y=455; W=220; H=82; Lines=@('PK id','name','email','message')},

        @{Id='checklist_batches'; Title='checklist_batches'; X=30; Y=520; W=250; H=136; Lines=@('PK id','FK org_id','FK project_id','title / module','status','FK assigned_qa_lead_id')},
        @{Id='checklist_items'; Title='checklist_items'; X=320; Y=520; W=250; H=154; Lines=@('PK id','FK batch_id','FK org_id','FK project_id','FK issue_id','status / priority','FK assigned_to_user_id')},
        @{Id='checklist_attachments'; Title='checklist_attachments'; X=610; Y=520; W=250; H=82; Lines=@('PK id','FK checklist_item_id','FK uploaded_by')},
        @{Id='checklist_batch_attachments'; Title='checklist_batch_attachments'; X=610; Y=620; W=250; H=82; Lines=@('PK id','FK checklist_batch_id','FK uploaded_by')},

        @{Id='ai_provider_configs'; Title='ai_provider_configs'; X=30; Y=780; W=250; H=118; Lines=@('PK id','provider_key','display_name','provider_type','FK created_by / updated_by')},
        @{Id='ai_models'; Title='ai_models'; X=320; Y=780; W=250; H=100; Lines=@('PK id','FK provider_config_id','model_id','display_name')},
        @{Id='ai_runtime_config'; Title='ai_runtime_config'; X=610; Y=780; W=250; H=100; Lines=@('PK id','FK default_provider_config_id','FK default_model_id','assistant / prompt')},
        @{Id='openclaw_runtime_config'; Title='openclaw_runtime_config'; X=900; Y=780; W=240; H=100; Lines=@('PK id','FK default_provider_config_id','FK default_model_id','runtime switches')},
        @{Id='openclaw_control_plane_state'; Title='openclaw_control_plane_state'; X=1180; Y=780; W=240; H=82; Lines=@('PK id','config_version','FK last_runtime_reload_requested_by')},
        @{Id='openclaw_runtime_status'; Title='openclaw_runtime_status'; X=1180; Y=880; W=240; H=82; Lines=@('PK id','runtime_version','last_seen_at')},
        @{Id='openclaw_reload_requests'; Title='openclaw_reload_requests'; X=900; Y=900; W=240; H=82; Lines=@('PK id','FK requested_by_user_id','reason')},
        @{Id='openclaw_requests'; Title='openclaw_requests'; X=30; Y=940; W=250; H=136; Lines=@('PK id','FK selected_org_id','FK selected_project_id','FK requested_by_user_id','FK provider_config_id / model_id','FK submitted_batch_id')},
        @{Id='openclaw_request_items'; Title='openclaw_request_items'; X=320; Y=940; W=250; H=100; Lines=@('PK id','FK openclaw_request_id','title','duplicate_status')},
        @{Id='openclaw_request_attachments'; Title='openclaw_request_attachments'; X=610; Y=940; W=250; H=82; Lines=@('PK id','FK openclaw_request_id','file_path')},

        @{Id='ai_chat_threads'; Title='ai_chat_threads'; X=30; Y=1125; W=250; H=118; Lines=@('PK id','FK org_id','FK user_id','FK checklist_project_id','existing / resolved batch refs')},
        @{Id='ai_chat_messages'; Title='ai_chat_messages'; X=320; Y=1125; W=250; H=100; Lines=@('PK id','FK thread_id','FK provider_config_id','FK model_id','role / status')},
        @{Id='ai_chat_message_attachments'; Title='ai_chat_message_attachments'; X=610; Y=1125; W=250; H=82; Lines=@('PK id','FK message_id','file_path')},
        @{Id='notifications'; Title='notifications'; X=900; Y=1125; W=280; H=118; Lines=@('PK id','FK recipient_user_id','FK actor_user_id','FK org_id / project_id','FK issue_id / checklist refs','severity')},
        @{Id='runtime_extension'; Title='runtime extension'; X=1180; Y=1115; W=240; H=110; Lines=@('ai_chat_generated_checklist_items','created by ai_chat.php','links assistant messages','to reviewed checklist drafts') }
    )

    $edges = @(
        @{From='users'; To='password_reset_requests'},
        @{From='users'; To='organizations'},
        @{From='users'; To='org_members'},
        @{From='organizations'; To='org_members'},
        @{From='organizations'; To='projects'},
        @{From='organizations'; To='issues'},
        @{From='projects'; To='issues'},
        @{From='issues'; To='issue_labels'},
        @{From='labels'; To='issue_labels'},
        @{From='issues'; To='issue_attachments'},
        @{From='organizations'; To='checklist_batches'},
        @{From='projects'; To='checklist_batches'},
        @{From='checklist_batches'; To='checklist_items'},
        @{From='organizations'; To='checklist_items'},
        @{From='projects'; To='checklist_items'},
        @{From='checklist_items'; To='checklist_attachments'},
        @{From='checklist_batches'; To='checklist_batch_attachments'},
        @{From='checklist_items'; To='issues'},
        @{From='users'; To='checklist_attachments'},
        @{From='users'; To='checklist_batch_attachments'},
        @{From='users'; To='ai_provider_configs'},
        @{From='ai_provider_configs'; To='ai_models'},
        @{From='ai_provider_configs'; To='ai_runtime_config'},
        @{From='ai_models'; To='ai_runtime_config'},
        @{From='ai_provider_configs'; To='openclaw_runtime_config'},
        @{From='ai_models'; To='openclaw_runtime_config'},
        @{From='users'; To='openclaw_runtime_config'},
        @{From='users'; To='openclaw_reload_requests'},
        @{From='users'; To='openclaw_control_plane_state'},
        @{From='organizations'; To='openclaw_requests'},
        @{From='projects'; To='openclaw_requests'},
        @{From='users'; To='openclaw_requests'},
        @{From='ai_provider_configs'; To='openclaw_requests'},
        @{From='ai_models'; To='openclaw_requests'},
        @{From='checklist_batches'; To='openclaw_requests'},
        @{From='openclaw_requests'; To='openclaw_request_items'},
        @{From='openclaw_requests'; To='openclaw_request_attachments'},
        @{From='organizations'; To='ai_chat_threads'},
        @{From='users'; To='ai_chat_threads'},
        @{From='projects'; To='ai_chat_threads'},
        @{From='ai_chat_threads'; To='ai_chat_messages'},
        @{From='ai_provider_configs'; To='ai_chat_messages'},
        @{From='ai_models'; To='ai_chat_messages'},
        @{From='ai_chat_messages'; To='ai_chat_message_attachments'},
        @{From='users'; To='notifications'},
        @{From='organizations'; To='notifications'},
        @{From='projects'; To='notifications'},
        @{From='issues'; To='notifications'},
        @{From='checklist_batches'; To='notifications'},
        @{From='checklist_items'; To='notifications'},
        @{From='ai_chat_messages'; To='runtime_extension'},
        @{From='ai_chat_threads'; To='runtime_extension'}
    )

    $lookup = @{}
    foreach ($node in $nodes) {
        $lookup[$node.Id] = $node
    }

    $sb = New-Object System.Text.StringBuilder
    [void]$sb.AppendLine('<svg xmlns="http://www.w3.org/2000/svg" width="1480" height="1300" viewBox="0 0 1480 1300">')
    [void]$sb.AppendLine('  <defs>')
    [void]$sb.AppendLine('    <marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse">')
    [void]$sb.AppendLine('      <path d="M 0 0 L 10 5 L 0 10 z" fill="#64748b"/>')
    [void]$sb.AppendLine('    </marker>')
    [void]$sb.AppendLine('  </defs>')
    [void]$sb.AppendLine('  <rect width="1480" height="1300" fill="#f8fafc"/>')
    [void]$sb.AppendLine('  <text x="40" y="42" font-size="26" font-family="Segoe UI, Arial, sans-serif" font-weight="700" fill="#0f172a">BugCatcher Consolidated Full ERD</text>')
    [void]$sb.AppendLine('  <text x="40" y="68" font-size="13" font-family="Segoe UI, Arial, sans-serif" fill="#334155">Current-system view derived from local_dev_full.sql, schema.sql, migrations, and ai_chat runtime schema helpers.</text>')
    [void]$sb.AppendLine('  <text x="40" y="760" font-size="18" font-family="Segoe UI, Arial, sans-serif" font-weight="700" fill="#0f172a">AI Admin, OpenClaw, AI Chat, and Notification Domain</text>')
    [void]$sb.AppendLine('  <text x="40" y="505" font-size="18" font-family="Segoe UI, Arial, sans-serif" font-weight="700" fill="#0f172a">Checklist Domain</text>')
    [void]$sb.AppendLine('  <text x="40" y="74" font-size="18" font-family="Segoe UI, Arial, sans-serif" font-weight="700" fill="#0f172a">Core Identity, Organization, Project, and Issue Domain</text>')

    foreach ($edge in $edges) {
        $from = $lookup[$edge.From]
        $to = $lookup[$edge.To]
        if ($to.X -gt $from.X) {
            $x1 = $from.X + $from.W
            $y1 = $from.Y + [int]($from.H / 2)
            $x2 = $to.X
            $y2 = $to.Y + [int]($to.H / 2)
        } elseif ($to.X -lt $from.X) {
            $x1 = $from.X
            $y1 = $from.Y + [int]($from.H / 2)
            $x2 = $to.X + $to.W
            $y2 = $to.Y + [int]($to.H / 2)
        } elseif ($to.Y -gt $from.Y) {
            $x1 = $from.X + [int]($from.W / 2)
            $y1 = $from.Y + $from.H
            $x2 = $to.X + [int]($to.W / 2)
            $y2 = $to.Y
        } else {
            $x1 = $from.X + [int]($from.W / 2)
            $y1 = $from.Y
            $x2 = $to.X + [int]($to.W / 2)
            $y2 = $to.Y + $to.H
        }

        $midX = [int](($x1 + $x2) / 2)
        $polyline = "$x1,$y1 $midX,$y1 $midX,$y2 $x2,$y2"
        [void]$sb.AppendLine("  <polyline points=""$polyline"" fill=""none"" stroke=""#94a3b8"" stroke-width=""2"" marker-end=""url(#arrow)"" opacity=""0.9""/>")
    }

    foreach ($node in $nodes) {
        $fill = if ($node.Id -eq 'runtime_extension') { '#fff7ed' } elseif ($node.Y -ge 760) { '#eff6ff' } elseif ($node.Y -ge 520) { '#ecfdf5' } else { '#ffffff' }
        $stroke = if ($node.Id -eq 'runtime_extension') { '#f97316' } elseif ($node.Y -ge 760) { '#2563eb' } elseif ($node.Y -ge 520) { '#059669' } else { '#475569' }
        [void]$sb.AppendLine("  <rect x=""$($node.X)"" y=""$($node.Y)"" rx=""12"" ry=""12"" width=""$($node.W)"" height=""$($node.H)"" fill=""$fill"" stroke=""$stroke"" stroke-width=""2""/>")
        [void]$sb.AppendLine("  <rect x=""$($node.X)"" y=""$($node.Y)"" rx=""12"" ry=""12"" width=""$($node.W)"" height=""32"" fill=""$stroke""/>")
        [void]$sb.AppendLine("  <text x=""$($node.X + 12)"" y=""$($node.Y + 22)"" font-size=""15"" font-family=""Segoe UI, Arial, sans-serif"" font-weight=""700"" fill=""#ffffff"">$([System.Security.SecurityElement]::Escape($node.Title))</text>")
        $lineY = $node.Y + 52
        foreach ($line in $node.Lines) {
            [void]$sb.AppendLine("  <text x=""$($node.X + 12)"" y=""$lineY"" font-size=""12"" font-family=""Consolas, 'Courier New', monospace"" fill=""#0f172a"">$([System.Security.SecurityElement]::Escape($line))</text>")
            $lineY += 18
        }
    }

    [void]$sb.AppendLine('  <text x="1180" y="1252" font-size="12" font-family="Segoe UI, Arial, sans-serif" fill="#7c2d12">Runtime extension note: ai_chat_generated_checklist_items is created dynamically by api/v1/lib/ai_chat.php.</text>')
    [void]$sb.AppendLine('</svg>')

    Set-Content -LiteralPath $OutputPath -Value $sb.ToString() -Encoding UTF8
}

if (-not (Test-Path $sourcePath)) {
    throw "Source manuscript not found: $sourcePath"
}

if (-not (Test-Path $finalDocxPath)) {
    throw "Expected template DOCX not found: $finalDocxPath"
}

if (-not (Test-Path $referenceDocxPath)) {
    Copy-Item -LiteralPath $finalDocxPath -Destination $referenceDocxPath
}

Write-ConsolidatedErdSvg -OutputPath $erdSvgPath

$imagePath = if (Test-Path $erdPngPath) { $erdPngPath } else { $erdSvgPath }
$imageEntryName = if ($imagePath.ToLowerInvariant().EndsWith('.png')) { 'word/media/BugCatcher_Full_ERD.png' } else { 'word/media/BugCatcher_Full_ERD.svg' }
$imageContentType = if ($imagePath.ToLowerInvariant().EndsWith('.png')) { 'image/png' } else { 'image/svg+xml' }
$imageRelationshipTarget = Split-Path $imageEntryName -Leaf

Copy-Item -LiteralPath $referenceDocxPath -Destination $finalDocxPath -Force

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($finalDocxPath, [System.IO.Compression.ZipArchiveMode]::Update)
try {
    $docEntry = $zip.GetEntry('word/document.xml')
    if (-not $docEntry) {
        throw 'word/document.xml not found in template docx.'
    }

    $reader = New-Object System.IO.StreamReader($docEntry.Open())
    try {
        $existingDocXml = $reader.ReadToEnd()
    } finally {
        $reader.Dispose()
    }

    $sectPrXml = Extract-SectPrXml -DocumentXml $existingDocXml
    $lines = [System.IO.File]::ReadAllLines($sourcePath)
    $headings = Read-SourceHeadings -Lines $lines
    $bodyXml = Build-DocumentBodyXml -Lines $lines -Headings $headings -ImageRelationshipId 'rIdBugCatcherErd'

    $documentXml = @"
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"
    xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"
    xmlns:o="urn:schemas-microsoft-com:office:office"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
    xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"
    xmlns:v="urn:schemas-microsoft-com:vml"
    xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing"
    xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
    xmlns:w10="urn:schemas-microsoft-com:office:word"
    xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
    xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"
    xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml"
    xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup"
    xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk"
    xmlns:wne="http://schemas.microsoft.com/office/2006/wordml"
    xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"
    xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
    xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"
    mc:Ignorable="w14 w15 wp14">
  <w:body>
$bodyXml
$sectPrXml
  </w:body>
</w:document>
"@

    Replace-ZipEntry -Archive $zip -EntryName 'word/document.xml' -Bytes ([System.Text.Encoding]::UTF8.GetBytes($documentXml))

    $relsEntry = $zip.GetEntry('word/_rels/document.xml.rels')
    if (-not $relsEntry) {
        throw 'word/_rels/document.xml.rels not found in template docx.'
    }

    $relsReader = New-Object System.IO.StreamReader($relsEntry.Open())
    try {
        $relsXml = $relsReader.ReadToEnd()
    } finally {
        $relsReader.Dispose()
    }

    $updatedRelsXml = Upsert-Relationship -Xml $relsXml -RelationshipId 'rIdBugCatcherErd' -Target "media/$imageRelationshipTarget"
    Replace-ZipEntry -Archive $zip -EntryName 'word/_rels/document.xml.rels' -Bytes ([System.Text.Encoding]::UTF8.GetBytes($updatedRelsXml))

    $contentTypesEntry = $zip.GetEntry('[Content_Types].xml')
    if (-not $contentTypesEntry) {
        throw '[Content_Types].xml not found in template docx.'
    }

    $contentReader = New-Object System.IO.StreamReader($contentTypesEntry.Open())
    try {
        $contentTypesXml = $contentReader.ReadToEnd()
    } finally {
        $contentReader.Dispose()
    }

    $extension = [System.IO.Path]::GetExtension($imagePath).TrimStart('.').ToLowerInvariant()
    $updatedContentTypesXml = Upsert-ContentType -Xml $contentTypesXml -Extension $extension -ContentType $imageContentType
    Replace-ZipEntry -Archive $zip -EntryName '[Content_Types].xml' -Bytes ([System.Text.Encoding]::UTF8.GetBytes($updatedContentTypesXml))

    Replace-ZipEntry -Archive $zip -EntryName $imageEntryName -Bytes ([System.IO.File]::ReadAllBytes($imagePath))
} finally {
    $zip.Dispose()
}

Write-Host "Generated SVG: $erdSvgPath"
if (Test-Path $erdPngPath) {
    Write-Host "Using PNG image for DOCX: $erdPngPath"
} else {
    Write-Host "PNG export not found. Using SVG image inside DOCX: $erdSvgPath"
}
Write-Host "Generated DOCX: $finalDocxPath"
