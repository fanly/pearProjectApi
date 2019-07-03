<?php
/**
 * Created by PhpStorm.
 * SystemUser: vilson
 * Date: 2018/7/10
 * Time: 12:38
 */

namespace app\project\behavior;


use app\common\Model\CommonModel;
use app\common\Model\Member;
use app\common\Model\Notify;
use app\common\Model\ProjectLog;
use app\common\Model\ProjectVersion;
use app\common\Model\TaskMember;
use app\common\Model\TaskStages;
use app\common\Model\TaskWorkflowRule;
use message\DingTalk;
use service\MessageService;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\DbException;
use think\facade\Log;

class Task
{
    /**
     * 任务操作钩子
     * @param $data
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    public function run($data)
    {
        $isRobot = (isset($data['data']) && isset($data['data']['is_robot']) && $data['data']['is_robot']) ? 1 : 0;
        $logData = ['member_code' => $data['memberCode'], 'source_code' => $data['taskCode'], 'remark' => $data['remark'], 'type' => $data['type'], 'content' => $data['content'], 'is_comment' => $data['isComment'], 'to_member_code' => $data['toMemberCode'], 'create_time' => nowTime(), 'code' => createUniqueCode('projectLog'), 'action_type' => 'task', 'is_robot' => $isRobot];
        $task = \app\common\Model\Task::where(['code' => $data['taskCode']])->find();
        $logData['project_code'] = $task['project_code'];
        $toMember = [];
        if ($data['toMemberCode']) {
            $toMember = Member::where(['code' => $data['toMemberCode']])->find();
        }
        $notifyData = [
            'title' => '',
            'content' => '',
            'type' => 'message',
            'action' => 'task',
            'terminal' => 'project',
        ];
        $remark = '';
        $content = '';
        switch ($data['type']) {
            case 'create':
                $icon = 'plus';
                $remark = '创建了任务 ';
                $content = $task['name'];
                break;
            case 'name':
                $icon = 'edit';
                $remark = '更新了内容 ';
                $content = $task['name'];
                break;
            case 'move':
                $icon = 'drag';
                $remark = '将任务移动到 ' . $data['data']['stageName'];
                $content = $task['name'];
                break;
            case 'content':
                $icon = 'file-text';
                $remark = '更新了备注 ';
                $content = $task['description'];
                break;
            case 'clearContent':
                $icon = 'file-text';
                $remark = '清空了备注 ';
                break;
            case 'done':
                $icon = 'check';
                $remark = '完成了任务 ';
                if ($task['version_code']) {
                    ProjectVersion::updateSchedule($task['version_code']);
                }
                break;
            case 'redo':
                $icon = 'border';
                $remark = '重做了任务 ';
                if ($task['version_code']) {
                    ProjectVersion::updateSchedule($task['version_code']);
                }
                break;
            case 'createChild':
                $icon = 'bars';
                $remark = '添加了子任务 ' . '"' . $data['data']['taskName'] . '"';
                break;
            case 'doneChild':
                $icon = 'bars';
                $remark = '完成了子任务 ' . '"' . $task['name'] . '"';
                break;
            case 'redoChild':
                $icon = 'undo';
                $remark = '重做了子任务 ' . '"' . $task['name'] . '"';
                break;
            case 'claim':
                $icon = 'user';
                $remark = '认领了任务 ';
                break;
            case 'assign':
                $icon = 'user';
                $remark = '指派给了 ' . $toMember['name'];
                break;
            case 'pri':
                $icon = 'user';
                $remark = '更新任务优先级为 ' . $task['priText'];
                break;
            case 'removeExecutor':
                $icon = 'user-delete';
                $remark = '移除了执行者 ';
                break;
            case 'changeState':
                $icon = 'edit';
                $taskState = TaskStages::where(['code' => $task['stage_code']])->find();
                $remark = '将任务移动到 ' . $taskState['name'];
                break;
            case 'inviteMember':
                $icon = 'user-add';
                $remark = '添加了参与者 ' . $toMember['name'];
                break;
            case 'removeMember':
                $icon = 'user-delete';
                $remark = '移除了参与者 ' . $toMember['name'];
                break;
            case 'setBeginTime':
                $icon = 'calendar';
                $remark = '更新开始时间为 ' . date('m月d日 H:i', strtotime($task['begin_time']));
                break;
            case 'clearBeginTime':
                $icon = 'calendar';
                $remark = '清除了开始时间 ';
                break;
            case 'setEndTime':
                $icon = 'calendar';
                $remark = '更新截止时间为 ' . date('m月d日 H:i', strtotime($task['end_time']));
                break;
            case 'clearEndTime':
                $icon = 'calendar';
                $remark = '清除了截止时间 ';
                break;
            case 'recycle':
                $icon = 'delete';
                $remark = '把任务移到了回收站 ';
                break;
            case 'recovery':
                $icon = 'undo';
                $remark = '恢复了任务 ';
                break;
            case 'linkFile':
                $icon = 'link';
                $remark = '关联了文件 ';
                $content = "<a target=\"_blank\" class=\"muted\" href=\"{$data['data']['url']} \">{$data['data']['title']}</a>";

                break;
            case 'unlinkFile':
                $icon = 'disconnect';
                $remark = '取消关联文件';
                $content = "<a target=\"_blank\" class=\"muted\" href=\"{$data['data']['url']} \">{$data['data']['title']}</a>";
                break;
            default:
                $icon = 'plus';
                $remark = ' 创建了任务 ';
                break;
        }
        $logData['icon'] = $icon;
        if ($logData['is_robot']) {
            $logData['icon'] = 'alert';
        }
        if (!$data['remark']) {
            $logData['remark'] = $remark;
        }
        if (!$data['content']) {
            $logData['content'] = $content;
        }
        ProjectLog::create($logData);
        //工作流事件
        if (!$isRobot && !$task['pcode']) {//子任务不触发
            $workflowActions = ['create', 'move', 'done', 'redo', 'assign', 'setEndTime', 'pri'];
            if (in_array($data['type'], $workflowActions)) {
                $taskStageCode = $task['stage_code'];
                $action = TaskWorkflowRule::getActionByTaskType($data['type']);
                $taskWorkflowRules = TaskWorkflowRule::where(['object_code' => $taskStageCode, 'sort' => 1])->order('id asc')->select();
                logRecord($taskWorkflowRules);
                if ($taskWorkflowRules) {
                    foreach ($taskWorkflowRules as $taskWorkflowRule) {
//                        $taskWorkflowRule = TaskWorkflowRule::where(['object_code' => $taskStageCode, 'sort' => 1])->order('id asc')->find();
                        if ($taskWorkflowRule) {
                            $nextTaskWorkflowRule = TaskWorkflowRule::where(['workflow_code' => $taskWorkflowRule['workflow_code'], 'sort' => 2])->find();
                            if ($nextTaskWorkflowRule) {
                                if ($nextTaskWorkflowRule['action'] == $action) {
                                    $goon = true;
                                    if ($action == 3) {
                                        //设置执行人
                                        $toMemberCode = $toMember['code'];
                                        if ($toMemberCode != $nextTaskWorkflowRule['object_code']) {
                                            $goon = false;
                                        }
                                    }
                                    if ($goon) {
                                        $doTaskWorkflowRule = TaskWorkflowRule::where(['workflow_code' => $taskWorkflowRule['workflow_code'], 'sort' => 3])->find();
                                        $doTaskWorkflowRule && TaskWorkflowRule::doAction($task, $doTaskWorkflowRule);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        //触发推送的事件
        $notifyActions = ['done', 'redo', 'assign'];
        if (in_array($data['type'], $notifyActions)) {
            //todo 短信,消息推送
            $notifyModel = new Notify();
            $member = Member::where(['code' => $data['memberCode']])->find();
            $notifyData['title'] = $member['name'] . ' ' . $remark;
            $notifyData['content'] = $task['name'];
            $notifyData['avatar'] = $member['avatar'];
            $taskMembers = TaskMember::where(['task_code' => $task['code']])->select()->toArray();
            if ($taskMembers) {
                $messageService = new MessageService();
                $messageDingTalk = new DingTalk();
                foreach ($taskMembers as $taskMember) {
                    if ($taskMember['member_code'] == $data['memberCode']) {
                        continue;//跳过产生者
                    }
                    $member = Member::where(['code' => $taskMember['member_code']])->find();
                    $result = $notifyModel->add($notifyData['title'], $notifyData['content'], $notifyData['type'], $data['memberCode'], $taskMember['member_code'], $notifyData['action'], json_encode($task), $notifyData['terminal'], $notifyData['avatar']);
                    if (isOpenDingTalkNoticePush()) {
                        if ($member['dingtalk_userid']) {
                            $params = [
                                'msgtype' => "oa",
                                'oa' => [
                                    'message_url' => 'http://dingtalk.com',
                                    'head' => ['bgcolor' => 'FFBBBBBB', 'text' => '任务动态'],
                                    'body' => ['title' => $notifyData['title'], 'content' => $notifyData['content']],
                                ]
                            ];
                            $messageDingTalk->sendCorporationMessage($member['dingtalk_userid'], $params);
                        }
                    }
                    if (isOpenNoticePush()) {
                        $messageService->sendToUid($taskMember['member_code'], ['content' => $notifyData['content'], 'title' => $notifyData['title'], 'data' => ['organizationCode' => getCurrentOrganizationCode()], 'notify' => $result], $notifyData['action']);
                    }
                }
            }
        }
    }
}
