"""Split ToolExecutionService into per-tool handler files."""
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "src", "App", "Services")
TOOLS = os.path.join(SRC, "Tools")
os.makedirs(TOOLS, exist_ok=True)

with open(os.path.join(SRC, "ToolExecutionService.php"), encoding="utf-8") as f:
    content = f.read()

# TodoistApiClient
m = re.search(r"(    public function makeTodoistRequest\(.*?\n    \})", content, re.S)
if m:
    body = m.group(1).replace("public function makeTodoistRequest", "public function request")
    client = f"""<?php

namespace App\\Services\\Tools;

use App\\Config;

class TodoistApiClient
{{
{body}
}}
"""
    with open(os.path.join(TOOLS, "TodoistApiClient.php"), "w", encoding="utf-8") as f:
        f.write(client)

# ToolStreamHelper trait
trait = """<?php

namespace App\\Services\\Tools;

use App\\AgentManager;

trait ToolStreamHelper
{
    protected function streamAgentCommentary(AgentManager $agent, array $messages, callable $emit, string $cleanJson): string
    {
        $aiCommentary = '';
        $commentaryBuffer = '';

        $agent->chat($messages, true, function ($chunk) use ($emit, &$aiCommentary, &$commentaryBuffer) {
            $aiCommentary .= $chunk;
            $commentaryBuffer .= $chunk;

            if (mb_check_encoding($commentaryBuffer, 'UTF-8')) {
                $emit('token', ['chunk' => $commentaryBuffer]);
                $commentaryBuffer = '';
            }
        });

        if (!empty($commentaryBuffer)) {
            $emit('token', ['chunk' => mb_convert_encoding($commentaryBuffer, 'UTF-8', 'UTF-8')]);
        }

        return $cleanJson . "\\n\\n" . $aiCommentary;
    }
}
"""
with open(os.path.join(TOOLS, "ToolStreamHelper.php"), "w", encoding="utf-8") as f:
    f.write(trait)

# Extract tool blocks
tool_map = {
    "SearchFilesTool": "Tool::SEARCH_FILES",
    "CreateTodoistTaskTool": "Tool::CREATE_TODOIST_TASK",
    "GetTodoistTasksTool": "Tool::GET_TODOIST_TASKS",
    "DeleteTodoistTaskTool": "Tool::DELETE_TODOIST_TASK",
    "UpdateTodoistTaskTool": "Tool::UPDATE_TODOIST_TASK",
    "GetEmailBriefingTool": "Tool::GET_EMAIL_BRIEFING",
}

for class_name, tool_const in tool_map.items():
    pattern = rf"if \(\$toolType === {re.escape(tool_const)}\) \{{(.*?)\n            \}} elseif \(\$toolType === Tool::"
    match = re.search(pattern, content, re.S)
    if not match and tool_const == "Tool::GET_EMAIL_BRIEFING":
        pattern = rf"if \(\$toolType === {re.escape(tool_const)}\) \{{(.*?)\n            \}}\n\n            return"
        match = re.search(pattern, content, re.S)
    if not match:
        print(f"WARN: no match for {class_name}")
        continue
    block = match.group(1)
    # indent block as method body
    lines = block.split("\n")
    method_body = "\n".join(lines)

    uses = "use App\\Database;\nuse App\\AgentManager;\nuse App\\Config;\nuse App\\Agents\\SchedulingAgent;\nuse App\\Agents\\TaskMatcher;\nuse App\\Services\\EmailService;\n"
    if class_name == "SearchFilesTool":
        uses = "use App\\Database;\nuse App\\AgentManager;\n"
    elif class_name == "DeleteTodoistTaskTool":
        uses = "use App\\AgentManager;\nuse App\\Agents\\TaskMatcher;\n"
    elif class_name == "GetEmailBriefingTool":
        uses = "use App\\Database;\nuse App\\AgentManager;\nuse App\\Agents\\SchedulingAgent;\nuse App\\Services\\EmailService;\n"

    php = f"""<?php

namespace App\\Services\\Tools;

{uses}
class {class_name}
{{
    use ToolStreamHelper;

    public function __construct(
        private Database $db,
        private AgentManager $agent,
        private string $uploadDir,
        private TodoistApiClient $todoist
    ) {{
    }}

    public function execute(array $toolData, int $sessionId, array $messages, callable $emit, string $cleanJson): string
    {{{method_body}
    }}
}}
"""
    with open(os.path.join(TOOLS, f"{class_name}.php"), "w", encoding="utf-8") as f:
        f.write(php)
    print(f"Wrote {class_name}")

print("Done")
