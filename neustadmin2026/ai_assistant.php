<?php
include('auth.php');

$conn = new mysqli("localhost", "root", "", "neust_gatepass_v3");
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Fetch active term settings from admin db
$active_school_year = '2025-2026';
$active_semester = '1st Semester';
$term_q = $conn->query("SELECT name, value FROM settings WHERE name IN ('active_school_year', 'active_semester')");
if ($term_q) {
    while ($row = $term_q->fetch_assoc()) {
        if ($row['name'] === 'active_school_year') {
            $active_school_year = $row['value'];
        } elseif ($row['name'] === 'active_semester') {
            $active_semester = $row['value'];
        }
    }
}
$safe_sy = $conn->real_escape_string($active_school_year);
$safe_sem = $conn->real_escape_string($active_semester);

// Pre-load at-risk students for the Parent Notification tab dropdown selector (attendance < 85%)
$at_risk_list = [];
$at_risk_q = $conn->query("
    SELECT stud.id, stud.name, stud.course, stud.year_level, stud.section,
           COUNT(a.id) AS total_classes,
           SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) AS attended_classes,
           SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absent_classes,
           ROUND((SUM(CASE WHEN a.status IN ('Present', 'Late') THEN 1 ELSE 0 END) / COUNT(a.id) * 100), 1) AS attendance_rate
    FROM attendance a
    JOIN schedules s ON a.schedule_id = s.id
    JOIN students stud ON a.student_id = stud.id
    WHERE s.school_year = '$safe_sy' AND s.semester = '$safe_sem'
    GROUP BY a.student_id
    HAVING total_classes > 0 AND attendance_rate < 85.0
    ORDER BY attendance_rate ASC
");

if ($at_risk_q) {
    while ($row = $at_risk_q->fetch_assoc()) {
        $at_risk_list[] = $row;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Assistant - Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="admin_assets/admin_sidebar.css">
    <link rel="stylesheet" href="admin_assets/admin_ai.css">
</head>
<body>
    <div class="app">
        <?php include('navbar.php'); ?>

        <main class="content" style="margin-top: 30px;">
    <!-- HUD Row: Server Connection & Active Model Status -->
    <div class="ai-hud-grid">
        <!-- Status Card -->
        <div class="ai-card status-hud">
            <div class="status-left">
                <div class="status-badge-container offline" id="statusBadgeContainer">
                    <i class="fa-solid fa-robot" id="statusRobotIcon"></i>
                </div>
                <div class="status-details">
                    <h3>Ollama Local Service</h3>
                    <span class="status-pill offline" id="statusPill">
                        <span class="status-dot offline" id="statusDot"></span>
                        <span id="statusText">Checking Connection...</span>
                    </span>
                </div>
            </div>
            
            <div class="ai-controls">
                <div class="ai-form-group">
                    <label for="ollamaHost">Server Address</label>
                    <input type="text" id="ollamaHost" class="ai-input" value="http://localhost:11434" placeholder="http://localhost:11434">
                </div>
                <div class="ai-form-group">
                    <label for="ollamaModel">Active LLM Model</label>
                    <select id="ollamaModel" class="ai-select">
                        <option value="deepseek-r1:1.5b">deepseek-r1:1.5b (Reasoning)</option>
                        <option value="qwen2.5:1.5b">qwen2.5:1.5b</option>
                        <option value="llama3">llama3 (8B)</option>
                        <option value="mistral">mistral (7B)</option>
                    </select>
                </div>
                <button type="button" class="ai-btn-sm" id="btnRefreshStatus">
                    <i class="fa-solid fa-arrows-rotate"></i> Reconnect
                </button>
            </div>
        </div>

        <!-- Academic Info Card -->
        <div class="ai-card" style="display: flex; flex-direction: column; justify-content: center; background: linear-gradient(135deg, #fdfbf7 0%, #f5f2eb 100%); border-color: #e5ded0;">
            <div style="font-size: 0.75rem; font-weight: 700; color: #a1824a; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px;">Context Parameters</div>
            <div style="font-size: 1.15rem; font-weight: 700; color: #3e2d0f; margin-bottom: 2px;">Active Academic Term</div>
            <div style="font-size: 0.85rem; color: #7c6843; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-graduation-cap" style="color: #c4a161;"></i> <?= htmlspecialchars($active_school_year) ?> – <?= htmlspecialchars($active_semester) ?>
            </div>
        </div>
    </div>

    <!-- Connection Offline Help Message (Hidden by default, shown if Ollama is not accessible) -->
    <div class="setup-guide-box" id="ollamaSetupGuide" style="display: none; margin-bottom: 24px;">
        <h4><i class="fa-solid fa-triangle-exclamation"></i> Action Required: Ollama Connection Refused</h4>
        <p>We could not reach Ollama on <code id="guideHost">http://localhost:11434</code>. Because this application processes institutional student records, AI analytics are performed <strong>entirely locally</strong> to secure student privacy. To enable AI features:</p>
        <ol>
            <li><strong>Install Ollama</strong> on your machine. Open terminal and run:
                <br><code style="display: block; margin: 6px 0; padding: 8px 12px; background: #fef9c3; border: 1px dashed #f59e0b; border-radius: 6px; font-family: monospace;">curl -fsSL https://ollama.com/install.sh | sh</code>
            </li>
            <li><strong>Start the Ollama daemon</strong>:
                <br><code style="display: block; margin: 6px 0; padding: 8px 12px; background: #fef9c3; border: 1px dashed #f59e0b; border-radius: 6px; font-family: monospace;">systemctl start ollama</code>
            </li>
            <li><strong>Pull a compatible model</strong> (e.g. DeepSeek R1 reasoning or Qwen model):
                <br><code style="display: block; margin: 6px 0; padding: 8px 12px; background: #fef9c3; border: 1px dashed #f59e0b; border-radius: 6px; font-family: monospace;">ollama pull deepseek-r1:1.5b</code>
                <span style="font-size: 0.75rem; color: #b45309; display: block; margin-top: 3px;">Or for general use: <code>ollama pull qwen2.5:1.5b</code></span>
            </li>
            <li>Make sure you have CORS enabled if hosting Ollama on a different IP than your web server. Click <strong>Reconnect</strong> above once running.</li>
        </ol>
    </div>

    <!-- Workspace Tabs -->
    <div class="ai-tabs">
        <button class="ai-tab-link active" onclick="switchTab(event, 'tabAnalytics')">
            <i class="fa-solid fa-chart-line"></i> Attendance Analytics Brief
        </button>
        <button class="ai-tab-link" onclick="switchTab(event, 'tabParentAlert')">
            <i class="fa-solid fa-envelope-open-text"></i> Parent Notification Drafter
        </button>
        <button class="ai-tab-link" onclick="switchTab(event, 'tabChatRoom')">
            <i class="fa-solid fa-comments"></i> Admin AI Chat Room
        </button>
    </div>

    <!-- Tab 1: Attendance Analytics Brief -->
    <div class="ai-tab-pane active" id="tabAnalytics">
        <div class="ai-card">
            <h3><i class="fa-solid fa-rectangle-list"></i> Automated Executive Attendance Analysis</h3>
            <p style="color: var(--ai-muted-slate); font-size: 0.88rem; margin: -6px 0 20px 0;">This utility gathers live metrics from the <code>attendance</code>, <code>students</code>, and <code>schedules</code> tables (including total scans, department rankings, and list of critical profiles) and passes the structured summary to Ollama to compose a brief with recommendations.</p>
            
            <button class="ai-primary-btn" id="btnRunAnalytics" onclick="generateAnalyticsBrief()">
                <i class="fa-solid fa-arrows-spin"></i> Compile Stats & Generate Executive Brief
            </button>

            <div class="output-layout">
                <div class="output-panel" id="analyticsOutputPanel">
                    <div class="output-placeholder" id="analyticsPlaceholder">
                        <i class="fa-solid fa-chart-pie"></i>
                        <h4>Awaiting Compilation</h4>
                        <p>Click the button above to compile attendance trends and initiate the offline LLM analysis.</p>
                    </div>
                    
                    <!-- Loading HUD -->
                    <div class="ai-loader-container" id="analyticsLoader" style="display: none;">
                        <div class="ai-pulse-ring">
                            <i class="fa-solid fa-brain"></i>
                        </div>
                        <div class="loader-status-text" id="analyticsLoaderStatus">Connecting to Database...</div>
                        <div class="loader-steps">
                            <span class="loader-step" id="stepDb">1. Fetching active term tables (Pending)</span>
                            <span class="loader-step" id="stepPrompt">2. Structuring prompt matrices (Pending)</span>
                            <span class="loader-step" id="stepLlm">3. LLM synthesis & reasoning (Pending)</span>
                        </div>
                    </div>

                    <!-- Clean Render Area -->
                    <div class="ai-markdown-content" id="analyticsRenderArea" style="display: none;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 2: Parent Notification Drafter -->
    <div class="ai-tab-pane" id="tabParentAlert">
        <div class="ai-card">
            <h3><i class="fa-solid fa-user-shield"></i> Absenteeism & Tardiness Alert Generator</h3>
            <p style="color: var(--ai-muted-slate); font-size: 0.88rem; margin: -6px 0 20px 0;">Generate polite, professional, and personalized notification letters for parents of students whose attendance rate has dropped below the school's critical threshold (80%).</p>
            
            <div class="selector-split">
                <!-- Sidebar Selection of At-Risk Students -->
                <div class="student-list-container">
                    <div style="font-size: 0.75rem; font-weight: 700; color: var(--ai-muted-slate); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">Monitored Students (&lt; 85% rate)</div>
                    <div id="studentList">
                        <?php if (empty($at_risk_list)): ?>
                            <div style="text-align: center; padding: 30px 10px; color: var(--ai-muted-slate); font-size: 0.85rem;">
                                <i class="fa-solid fa-circle-check" style="font-size: 2rem; color: #10b981; margin-bottom: 8px; display: block;"></i>
                                <strong>All clear!</strong> No registered student is below the critical threshold.
                            </div>
                        <?php else: ?>
                            <?php foreach ($at_risk_list as $index => $stud): ?>
                                <?php 
                                    $rate = floatval($stud['attendance_rate']);
                                    $badgeClass = 'medium';
                                    if ($rate < 50.0) {
                                        $badgeClass = 'critical';
                                    } elseif ($rate < 70.0) {
                                        $badgeClass = 'high';
                                    }
                                ?>
                                <div class="student-item" onclick="selectStudent(this, <?= $stud['id'] ?>)">
                                    <div class="stud-badge-info">
                                        <h4><?= htmlspecialchars($stud['name']) ?></h4>
                                        <p><?= htmlspecialchars($stud['course']) ?> • Yr <?= htmlspecialchars($stud['year_level']) ?>-<?= htmlspecialchars($stud['section']) ?></p>
                                    </div>
                                    <span class="stud-rate-badge <?= $badgeClass ?>"><?= $stud['attendance_rate'] ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Email Output Panel -->
                <div class="output-panel" id="emailOutputPanel" style="min-height: 400px;">
                    <div class="output-placeholder" id="emailPlaceholder">
                        <i class="fa-solid fa-paper-plane"></i>
                        <h4>Select a Student Profile</h4>
                        <p>Pick an at-risk profile from the left pane to view metrics and compile a personalized communication draft.</p>
                    </div>

                    <!-- Selected Student Mini-HUD -->
                    <div id="emailActiveHud" class="email-active-hud" style="display: none;">
                        <div>
                            <h4 id="hudStudentName" style="margin: 0 0 4px; font-size: 1rem; font-weight: 700; color: var(--ai-dark-slate);">Student Profile</h4>
                            <p id="hudStudentDetails" style="margin: 0; font-size: 0.8rem; color: var(--ai-muted-slate);">Course details...</p>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 0.7rem; font-weight: 700; color: var(--ai-muted-slate); text-transform: uppercase;">Realtime Attendance</div>
                            <strong id="hudStudentRate" style="font-size: 1.2rem; color: #ef4444;">0%</strong>
                        </div>
                    </div>

                    <button class="ai-primary-btn" id="btnDraftEmail" onclick="generateParentEmail()" style="display: none; margin-bottom: 20px; width: 100%; justify-content: center;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Generate AI Parent Alert Notification
                    </button>

                    <!-- Email Loading State -->
                    <div class="ai-loader-container" id="emailLoader" style="display: none; padding: 60px 20px;">
                        <div class="ai-pulse-ring">
                            <i class="fa-solid fa-envelope"></i>
                        </div>
                        <div class="loader-status-text">Drafting notification...</div>
                        <span style="font-size: 0.75rem; color: var(--ai-muted-slate);">Constructing attendance summary context...</span>
                    </div>

                    <!-- Active Email Container -->
                    <div class="email-container" id="emailDraftContainer" style="display: none;">
                        <div class="email-header-field">
                            <span class="label">Subject:</span>
                            <span class="value" id="emailSubjectLine">Attendance Notice Alert - Office of Student Affairs</span>
                        </div>
                        <div class="email-body-box" id="emailBodyText">Email body draft goes here...</div>
                        <div class="draft-toolbar">
                            <button class="ai-btn-sm" onclick="copyEmailToClipboard()" style="background: var(--ai-primary); color: white; border-color: var(--ai-primary);">
                                <i class="fa-solid fa-copy"></i> Copy Draft
                            </button>
                            <button class="ai-btn-sm" id="btnSendAiEmail" onclick="sendParentNotificationEmail()" style="background: #10b981; color: white; border-color: #10b981; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-paper-plane"></i> Send Email to Parent
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 3: Custom Chat Room -->
    <div class="ai-tab-pane" id="tabChatRoom">
        <div class="ai-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                <h3 style="margin: 0;"><i class="fa-solid fa-comments"></i> Admin Assistant Chat Workspace</h3>
                <button type="button" class="ai-btn-sm" onclick="clearChatHistory()" style="background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 5px 12px; font-size: 0.8rem; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-trash-can"></i> Clear History
                </button>
            </div>
            <p style="color: var(--ai-muted-slate); font-size: 0.88rem; margin: 4px 0 20px 0;">Chat directly with the local AI. The model is injected with live facts about total registered student profiles, total scan records, and critical rates, allowing you to ask system-specific questions.</p>

            <div class="chat-room-layout">
                <!-- Message Thread Scroll -->
                <div class="chat-messages-scroll" id="chatThread">
                    <div class="chat-bubble bot">
                        <div class="chat-avatar">AI</div>
                        <div class="chat-content-wrap">
                            Hello! I am your <strong>Gatepass AI Assistant</strong>. I have direct access to standard database statistics for the <strong><?= htmlspecialchars($active_school_year) ?></strong> term. How can I help you manage security logs, check student records, or optimize gatepass operations today?
                        </div>
                    </div>
                </div>

                <!-- Quick Suggested Prompt Pills -->
                <div class="chat-suggested-pills">
                    <span class="suggested-pill" onclick="insertSuggestedPrompt('How many registered student profiles are in the database?')">Total Students Tracked</span>
                    <span class="suggested-pill" onclick="insertSuggestedPrompt('Give me a bulleted summary of student risk levels.')">List At-Risk Thresholds</span>
                    <span class="suggested-pill" onclick="insertSuggestedPrompt('Explain how the RFID scanner client passes UID logs to scan.php.')">How RFID Scan Works</span>
                </div>

                <!-- Input box -->
                <div class="chat-input-bar">
                    <textarea class="chat-textarea" id="chatInput" placeholder="Ask a question about students, schedules, or scanning mechanics..." onkeydown="handleChatKeydown(event)"></textarea>
                    <button class="chat-send-btn" id="btnSendChat" onclick="submitChatMessage()">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
            </div>
        </div>
    </div>
</main>
</div>

<script>
let selectedStudentId = null;
let selectedStudentObj = null;
let chatHistory = [];
let ollamaConnected = false;

document.addEventListener("DOMContentLoaded", function () {
    // 1. Initial Ollama status HUD ping
    checkOllamaStatus();

    // 2. Set listener on refresh button
    document.getElementById("btnRefreshStatus").addEventListener("click", checkOllamaStatus);

    // 3. Restore persisted chat history if available
    loadSavedChatHistory();
});

// Switch Tab
function switchTab(evt, tabId) {
    const tabs = document.getElementsByClassName("ai-tab-pane");
    for (let i = 0; i < tabs.length; i++) {
        tabs[i].classList.remove("active");
    }
    
    const links = document.getElementsByClassName("ai-tab-link");
    for (let i = 0; i < links.length; i++) {
        links[i].classList.remove("active");
    }
    
    document.getElementById(tabId).classList.add("active");
    evt.currentTarget.classList.add("active");
}

// Check Ollama status on backend
async function checkOllamaStatus() {
    const host = document.getElementById("ollamaHost").value.trim();
    const model = document.getElementById("ollamaModel").value;
    
    const pill = document.getElementById("statusPill");
    const dot = document.getElementById("statusDot");
    const text = document.getElementById("statusText");
    const hudBadge = document.getElementById("statusBadgeContainer");
    const setupGuide = document.getElementById("ollamaSetupGuide");
    const guideHost = document.getElementById("guideHost");

    // Show loading
    text.innerText = "Pinging local daemon...";
    pill.className = "status-pill offline";
    dot.className = "status-dot offline";
    hudBadge.className = "status-badge-container offline";

    try {
        const response = await fetch("ai_backend.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                action: "check_status",
                host: host,
                model: model
            })
        });
        
        const data = await response.json();
        
        if (data.success && data.available) {
            // Online!
            ollamaConnected = true;
            text.innerText = "ONLINE - connected";
            pill.className = "status-pill online";
            dot.className = "status-dot online";
            hudBadge.className = "status-badge-container online";
            setupGuide.style.display = "none";
            
            // Populate models list dynamically if models returned
            if (data.models && data.models.length > 0) {
                const selectEl = document.getElementById("ollamaModel");
                
                // Filter out llama3.2 models
                const availableModels = data.models.filter(m => !m.toLowerCase().startsWith("llama3.2"));
                
                if (availableModels.length > 0) {
                    // Save current selection
                    const prevVal = selectEl.value;
                    
                    // Clear and rebuild options
                    selectEl.innerHTML = "";
                    availableModels.forEach(modelName => {
                        const opt = document.createElement("option");
                        opt.value = modelName;
                        opt.text = modelName;
                        if (modelName === prevVal || modelName.startsWith(prevVal)) {
                            opt.selected = true;
                        }
                        selectEl.appendChild(opt);
                    });
                    
                    // If previous selected is not in list, select first available model
                    if (!availableModels.includes(prevVal)) {
                        const matched = availableModels.find(m => m.startsWith(prevVal.split(':')[0]));
                        if (matched) {
                            selectEl.value = matched;
                        } else if (selectEl.options.length > 0) {
                            selectEl.selectedIndex = 0;
                        }
                    }
                }
            }
        } else {
            // Offline
            throw new Error("Local daemon returned failure status.");
        }
    } catch (err) {
        ollamaConnected = false;
        text.innerText = "OFFLINE - refused";
        pill.className = "status-pill offline";
        dot.className = "status-dot offline";
        hudBadge.className = "status-badge-container offline";
        
        // Show setup guide
        guideHost.innerText = host;
        setupGuide.style.display = "block";
    }
}

// Format Markdown to Basic HTML (simple renderer supporting clean Markdown Tables)
function renderMarkdown(text) {
    if (!text) return "";
    
    // Safety escape HTML tags first
    let esc = text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
        
    // Parse Markdown Tables BEFORE other replacements to avoid splitting rows
    let lines = esc.split("\n");
    let inTable = false;
    let tableRows = [];
    let finalLines = [];
    
    for (let i = 0; i < lines.length; i++) {
        let line = lines[i].trim();
        
        // Match table rows starting and ending with pipe or containing multiple pipes
        if (line.startsWith("|") && line.endsWith("|")) {
            if (!inTable) {
                inTable = true;
                tableRows = [];
            }
            tableRows.push(line);
        } else {
            if (inTable) {
                let tableHtml = renderTableHtml(tableRows);
                finalLines.push(tableHtml);
                inTable = false;
            }
            finalLines.push(lines[i]);
        }
    }
    if (inTable) {
        let tableHtml = renderTableHtml(tableRows);
        finalLines.push(tableHtml);
    }
    
    esc = finalLines.join("\n");
    
    // Headers
    esc = esc.replace(/^### (.*?)$/gm, "<h3>$1</h3>");
    esc = esc.replace(/^## (.*?)$/gm, "<h3>$1</h3>");
    esc = esc.replace(/^# (.*?)$/gm, "<h3>$1</h3>");
    
    // Bold
    esc = esc.replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
    
    // List Items
    esc = esc.replace(/^\- (.*?)$/gm, "<li>$1</li>");
    esc = esc.replace(/^\* (.*?)$/gm, "<li>$1</li>");
    
    // Paragraph spacing (wrap double line breaks)
    esc = esc.replace(/\n\n/g, "</p><p>");
    
    // Single line breaks
    esc = esc.replace(/\n/g, "<br>");
    
    // Spacing cleanups after block tags to avoid redundant double spacing
    esc = esc.replace(/<\/tr><br>/g, "</tr>");
    esc = esc.replace(/<\/thead><br>/g, "</thead>");
    esc = esc.replace(/<\/tbody><br>/g, "</tbody>");
    esc = esc.replace(/<\/table><br>/g, "</table>");
    esc = esc.replace(/<\/div><br>/g, "</div>");
    esc = esc.replace(/<h3>(.*?)<\/h3><br>/g, "<h3>$1</h3>");
    
    return "<p>" + esc + "</p>";
}

// Sub-helper: Compile array of table rows into premium styled HTML
function renderTableHtml(rows) {
    if (rows.length === 0) return "";
    
    let html = '<div class="ai-table-wrapper"><table class="ai-premium-table">';
    let hasHeader = false;
    let tbodyStarted = false;
    
    for (let r = 0; r < rows.length; r++) {
        let row = rows[r];
        
        // Skip alignment divider row: e.g. |---|---| or |:---|---:|
        if (row.match(/^\|?\s*:?-+:?\s*\|/)) {
            continue;
        }
        
        let cells = row.split("|").map(c => c.trim());
        // Remove first and last elements since they represent border boundaries of the pipes
        if (cells[0] === "") cells.shift();
        if (cells[cells.length - 1] === "") cells.pop();
        
        if (!hasHeader) {
            html += '<thead><tr>';
            cells.forEach(cell => {
                html += '<th>' + cell + '</th>';
            });
            html += '</tr></thead>';
            hasHeader = true;
        } else {
            if (!tbodyStarted) {
                html += '<tbody>';
                tbodyStarted = true;
            }
            html += '<tr>';
            cells.forEach(cell => {
                html += '<td>' + cell + '</td>';
            });
            html += '</tr>';
        }
    }
    
    if (tbodyStarted) {
        html += '</tbody>';
    }
    html += '</table></div>';
    return html;
}

// Generate Executive Analytics Brief
async function generateAnalyticsBrief() {
    const host = document.getElementById("ollamaHost").value;
    const model = document.getElementById("ollamaModel").value;
    
    const placeholder = document.getElementById("analyticsPlaceholder");
    const loader = document.getElementById("analyticsLoader");
    const renderArea = document.getElementById("analyticsRenderArea");
    const statusText = document.getElementById("analyticsLoaderStatus");
    const btn = document.getElementById("btnRunAnalytics");

    // UI state prep
    btn.disabled = true;
    placeholder.style.display = "none";
    renderArea.style.display = "none";
    loader.style.display = "flex";
    
    const stepDb = document.getElementById("stepDb");
    const stepPrompt = document.getElementById("stepPrompt");
    const stepLlm = document.getElementById("stepLlm");
    
    // Reset steps
    stepDb.className = "loader-step active";
    stepDb.innerText = "1. Fetching active term tables (Running...)";
    stepPrompt.className = "loader-step";
    stepPrompt.innerText = "2. Structuring prompt matrices (Pending)";
    stepLlm.className = "loader-step";
    stepLlm.innerText = "3. LLM synthesis & reasoning (Pending)";

    try {
        // Step 1: Simulated step transitions for gorgeous micro-animation
        await new Promise(r => setTimeout(r, 600));
        stepDb.className = "loader-step done";
        stepDb.innerText = "1. Fetching active term tables (Completed)";
        
        stepPrompt.className = "loader-step active";
        stepPrompt.innerText = "2. Structuring prompt matrices (Running...)";
        statusText.innerText = "Compiling performance analytics...";
        
        await new Promise(r => setTimeout(r, 600));
        stepPrompt.className = "loader-step done";
        stepPrompt.innerText = "2. Structuring prompt matrices (Completed)";
        
        stepLlm.className = "loader-step active";
        stepLlm.innerText = "3. LLM synthesis & reasoning (Awaiting model response...)";
        statusText.innerText = "Synthesizing AI analysis brief...";

        // Step 2: Trigger backend query
        const response = await fetch("ai_backend.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                action: "analyze_attendance",
                host: host,
                model: model
            })
        });

        const data = await response.json();
        
        if (data.success) {
            stepLlm.className = "loader-step done";
            stepLlm.innerText = "3. LLM synthesis & reasoning (Success)";
            
            // Render HTML
            renderArea.innerHTML = renderMarkdown(data.analysis);
            
            // Visual Swap
            loader.style.display = "none";
            renderArea.style.display = "block";
        } else {
            throw new Error(data.message || "Failed during Ollama query.");
        }
    } catch (err) {
        loader.style.display = "none";
        placeholder.style.display = "flex";
        
        // Show inline error alert
        const placeholderHeader = placeholder.querySelector("h4");
        const placeholderText = placeholder.querySelector("p");
        placeholderHeader.innerText = "Analysis Failed";
        placeholderText.innerHTML = `<span style="color: #ef4444; font-weight: 700;">Error: ${err.message}</span><br>Verify Ollama local daemon is running or pull the model using terminal.`;
    } finally {
        btn.disabled = false;
    }
}

// Select student from Monitored Students sidebar
function selectStudent(element, id) {
    // Styling active item
    const items = document.getElementsByClassName("student-item");
    for (let i = 0; i < items.length; i++) {
        items[i].classList.remove("active");
    }
    element.classList.add("active");
    
    // Parse student details
    selectedStudentId = id;
    
    // Fetch info from selected item's raw text (or we can query details)
    const name = element.querySelector("h4").innerText;
    const desc = element.querySelector("p").innerText;
    const rateText = element.querySelector(".stud-rate-badge").innerText;
    
    selectedStudentObj = {
        id: id,
        name: name,
        desc: desc,
        rate: rateText
    };

    // Show interactive HUD inside panel
    document.getElementById("emailPlaceholder").style.display = "none";
    document.getElementById("emailDraftContainer").style.display = "none";
    
    const activeHud = document.getElementById("emailActiveHud");
    activeHud.style.display = "flex";
    document.getElementById("hudStudentName").innerText = name;
    document.getElementById("hudStudentDetails").innerText = desc;
    
    const rateEl = document.getElementById("hudStudentRate");
    rateEl.innerText = rateText;
    
    // Rate style logic
    const rateVal = parseFloat(rateText);
    if (rateVal < 50.0) {
        rateEl.style.color = "#ef4444";
    } else if (rateVal < 70.0) {
        rateEl.style.color = "#f59e0b";
    } else {
        rateEl.style.color = "#2563eb";
    }

    document.getElementById("btnDraftEmail").style.display = "inline-flex";
}

// Generate Parent Email Draft
async function generateParentEmail() {
    if (!selectedStudentId) return;
    
    const host = document.getElementById("ollamaHost").value;
    const model = document.getElementById("ollamaModel").value;

    const loader = document.getElementById("emailLoader");
    const container = document.getElementById("emailDraftContainer");
    const btnDraft = document.getElementById("btnDraftEmail");
    const activeHud = document.getElementById("emailActiveHud");

    // Swap states
    activeHud.style.display = "none";
    btnDraft.style.display = "none";
    container.style.display = "none";
    loader.style.display = "flex";

    try {
        const response = await fetch("ai_backend.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                action: "draft_email",
                student_id: selectedStudentId,
                host: host,
                model: model
            })
        });

        const data = await response.json();
        
        if (data.success) {
            // Render email details
            const rawDraft = data.draft;
            
            // Separate subject and body if LLM wrote subject
            let subject = `URGENT Attendance Alert: Attendance Review for ${data.student.name}`;
            let body = rawDraft;
            
            const subjectMatch = rawDraft.match(/Subject:\s*(.*?)\n/i);
            if (subjectMatch) {
                subject = subjectMatch[1];
                body = rawDraft.replace(/Subject:\s*.*?\n/i, "").trim();
            }

            document.getElementById("emailSubjectLine").innerText = subject;
            document.getElementById("emailBodyText").innerText = body;
            
            loader.style.display = "none";
            container.style.display = "flex";
        } else {
            throw new Error(data.message || "Failed pulling email template from Ollama.");
        }
    } catch (err) {
        loader.style.display = "none";
        activeHud.style.display = "flex";
        btnDraft.style.display = "inline-flex";
        alert("Drafting failed: " + err.message);
    }
}

// Copy drafted email to clipboard
function copyEmailToClipboard() {
    const subject = document.getElementById("emailSubjectLine").innerText;
    const body = document.getElementById("emailBodyText").innerText;
    
    const fullText = `Subject: ${subject}\n\n${body}`;
    
    navigator.clipboard.writeText(fullText).then(() => {
        // Visual indicator on copy
        const copyBtn = document.querySelector(".draft-toolbar .ai-btn-sm");
        const origHtml = copyBtn.innerHTML;
        copyBtn.innerHTML = "<i class='fa-solid fa-check'></i> Copied!";
        copyBtn.style.background = "#10b981";
        copyBtn.style.borderColor = "#10b981";
        
        setTimeout(() => {
            copyBtn.innerHTML = origHtml;
            copyBtn.style.background = "var(--ai-primary)";
            copyBtn.style.borderColor = "var(--ai-primary)";
        }, 2000);
    }).catch(err => {
        alert("Could not copy draft: " + err);
    });
}

// Send personalized notification email via mailer.php
async function sendParentNotificationEmail() {
    if (!selectedStudentId) return;
    
    const subject = document.getElementById("emailSubjectLine").innerText;
    const body = document.getElementById("emailBodyText").innerText;
    const btnSend = document.getElementById("btnSendAiEmail");
    const origHtml = btnSend.innerHTML;

    if (!confirm("Are you sure you want to send this personalized AI attendance alert to the student's registered parents?")) {
        return;
    }

    // UI loading state
    btnSend.disabled = true;
    btnSend.style.background = "#6b7280";
    btnSend.style.borderColor = "#6b7280";
    btnSend.innerHTML = "<i class='fa-solid fa-circle-notch fa-spin'></i> Dispatched sending...";

    try {
        const response = await fetch("ai_backend.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                action: "send_ai_email",
                student_id: selectedStudentId,
                subject: subject,
                body: body
            })
        });

        const data = await response.json();

        if (data.success) {
            btnSend.style.background = "#10b981";
            btnSend.style.borderColor = "#10b981";
            btnSend.innerHTML = "<i class='fa-solid fa-circle-check'></i> Sent Successfully!";
            
            alert(data.message);
            
            setTimeout(() => {
                btnSend.disabled = false;
                btnSend.innerHTML = origHtml;
            }, 3000);
        } else {
            throw new Error(data.message || "Failed dispatching email notification.");
        }
    } catch (err) {
        btnSend.disabled = false;
        btnSend.style.background = "#ef4444";
        btnSend.style.borderColor = "#ef4444";
        btnSend.innerHTML = "<i class='fa-solid fa-circle-xmark'></i> Sending Failed";
        
        alert("Email Sending Failed: " + err.message);
        
        setTimeout(() => {
            btnSend.style.background = "#10b981";
            btnSend.style.borderColor = "#10b981";
            btnSend.innerHTML = origHtml;
        }, 3000);
    }
}

// Insert Suggested Prompt Pill into textarea
function insertSuggestedPrompt(text) {
    const input = document.getElementById("chatInput");
    input.value = text;
    input.focus();
}

// Handle Keydown in chat textarea (Submit on Enter)
function handleChatKeydown(event) {
    if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        submitChatMessage();
    }
}

// Submit Chat Message
async function submitChatMessage() {
    const input = document.getElementById("chatInput");
    const message = input.value.trim();
    if (!message) return;

    const host = document.getElementById("ollamaHost").value;
    const model = document.getElementById("ollamaModel").value;
    const thread = document.getElementById("chatThread");
    const btnSend = document.getElementById("btnSendChat");

    // Append User Message bubble
    const userBubble = document.createElement("div");
    userBubble.className = "chat-bubble user";
    userBubble.innerHTML = `
        <div class="chat-avatar">AD</div>
        <div class="chat-content-wrap">${escapeHtml(message)}</div>
    `;
    thread.appendChild(userBubble);
    thread.scrollTop = thread.scrollHeight;
    
    // Clear field & disable inputs
    input.value = "";
    input.disabled = true;
    btnSend.disabled = true;

    // Append Typing indicator
    const typingBubble = document.createElement("div");
    typingBubble.className = "chat-bubble bot";
    typingBubble.id = "chatTypingIndicator";
    typingBubble.innerHTML = `
        <div class="chat-avatar">AI</div>
        <div class="chat-content-wrap" style="color: var(--ai-muted-slate); font-style: italic;">
            <i class="fa-solid fa-circle-notch fa-spin"></i> Gatepass AI is reasoning...
        </div>
    `;
    thread.appendChild(typingBubble);
    thread.scrollTop = thread.scrollHeight;

    // Keep history structured
    chatHistory.push({ role: "user", content: message });
    saveChatHistory();

    try {
        const response = await fetch("ai_backend.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                action: "custom_chat",
                message: message,
                history: chatHistory,
                host: host,
                model: model
            })
        });

        const data = await response.json();
        
        // Remove typing indicator
        const indicator = document.getElementById("chatTypingIndicator");
        if (indicator) indicator.remove();

        if (data.success) {
            const botResponse = data.response;
            chatHistory.push({ role: "assistant", content: botResponse });
            saveChatHistory();
            
            // Append Bot message bubble
            const botBubble = document.createElement("div");
            botBubble.className = "chat-bubble bot";
            botBubble.innerHTML = `
                <div class="chat-avatar">AI</div>
                <div class="chat-content-wrap">${renderMarkdown(botResponse)}</div>
            `;
            thread.appendChild(botBubble);
        } else {
            throw new Error(data.message || "Failed retrieving bot response.");
        }
    } catch (err) {
        // Remove typing indicator
        const indicator = document.getElementById("chatTypingIndicator");
        if (indicator) indicator.remove();
        
        // Append error bubble
        const errBubble = document.createElement("div");
        errBubble.className = "chat-bubble bot";
        errBubble.innerHTML = `
            <div class="chat-avatar" style="background:#ef4444;">AI</div>
            <div class="chat-content-wrap" style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c;">
                <strong>Chat Request Failed:</strong> ${err.message}<br>Please check that the local Ollama service is running on port 11434.
            </div>
        `;
        thread.appendChild(errBubble);
    } finally {
        input.disabled = false;
        btnSend.disabled = false;
        input.focus();
        thread.scrollTop = thread.scrollHeight;
    }
}

// Utility to escape raw HTML text
function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Restore saved chat history from localStorage
function loadSavedChatHistory() {
    const saved = localStorage.getItem('gatepass_ai_chat_history');
    if (!saved) return;

    try {
        const parsed = JSON.parse(saved);
        if (Array.isArray(parsed) && parsed.length > 0) {
            chatHistory = parsed;
            const thread = document.getElementById("chatThread");
            
            parsed.forEach(msg => {
                const bubble = document.createElement("div");
                if (msg.role === "user") {
                    bubble.className = "chat-bubble user";
                    bubble.innerHTML = `
                        <div class="chat-avatar">AD</div>
                        <div class="chat-content-wrap">${escapeHtml(msg.content)}</div>
                    `;
                } else if (msg.role === "assistant") {
                    bubble.className = "chat-bubble bot";
                    bubble.innerHTML = `
                        <div class="chat-avatar">AI</div>
                        <div class="chat-content-wrap">${renderMarkdown(msg.content)}</div>
                    `;
                }
                thread.appendChild(bubble);
            });

            thread.scrollTop = thread.scrollHeight;
        }
    } catch (e) {
        console.error("Error restoring chat history:", e);
    }
}

// Save chat history to localStorage
function saveChatHistory() {
    try {
        localStorage.setItem('gatepass_ai_chat_history', JSON.stringify(chatHistory));
    } catch (e) {
        console.error("Error saving chat history:", e);
    }
}

// Clear chat history
function clearChatHistory() {
    if (chatHistory.length > 0 && !confirm("Are you sure you want to clear the chat history?")) {
        return;
    }
    chatHistory = [];
    localStorage.removeItem('gatepass_ai_chat_history');

    const thread = document.getElementById("chatThread");
    thread.innerHTML = `
        <div class="chat-bubble bot">
            <div class="chat-avatar">AI</div>
            <div class="chat-content-wrap">
                Hello! I am your <strong>Gatepass AI Assistant</strong>. How can I help you manage security logs, check student records, or optimize gatepass operations today?
            </div>
        </div>
    `;
}
</script>
</body>
</html>
