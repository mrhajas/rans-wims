<?php

use App\Support\Workflow\WorkflowModules as W;

// Starting values reproduce the WIMSChat notification settings: the bell only (email only for the types that were ticked), to the person
// the message is for. "The person the notice is for" is: the member mentioned, replied to, nudged, away, invited / added / removed, or the
// person who invited someone who has just joined. Roles and named people added here only receive it when they are also that person, so
// nobody can read other people's chats through this page. Personal choices (muting a conversation, "Do Not Disturb", being active in WIMS)
// still apply: a muted or active person is not told about an ordinary message.
$sms = "WIMS - #title\n#message";
$who = 'the member of the conversation the notice is for';

return [
    'key' => 'wimschat', 'path' => 'wimschat/workflow', 'page_route' => 'wimschat.workflow', 'update_route' => 'wimschat.workflow.update',
    'title' => 'WIMSChat Alerts', 'subtitle' => 'Decide how people are told about chat activity (bell, e-mail, SMS, WhatsApp) and with which words. Plain messages only notify people who are away, folded into one line per conversation; mentions, replies, nudges and group changes always notify.',
    'record' => 'message', 'owner_label' => $who,
    'events' => [
        'message' => W::ev('New message while away', 'A message arrives and the person is not active in WIMS (and has not muted the conversation). The bell shows one line per conversation.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'chat']),
        'mention' => W::ev('Mention', 'Someone @mentions the person.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'chat']),
        'reply' => W::ev('Reply', 'Someone replies to the person\'s message.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'reply']),
        'nudge' => W::ev('Nudge', 'Someone nudges the person or the group.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'bell']),
        'group_invite' => W::ev('Invited to a group', 'The person is invited to join a group and has to accept.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'users']),
        'group_added' => W::ev('Added to a group', 'The person is added to a group.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'users']),
        'group_removed' => W::ev('Removed from a group', 'The person is removed from a group.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'cross']),
        'group_joined' => W::ev('Joined the group', 'Someone accepts an invitation: the person who invited them is told.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'tick']),
        // new events: off until a Super Admin switches them on
        'group_updated' => W::ev('Group changed', 'A group administrator renames the group or changes its description or picture: the other members are told.', ['owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'edit']),
        'group_role_changed' => W::ev('Group role changed', 'A group administrator makes a member an administrator, or an ordinary member again: that member is told.', ['owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'users']),
        'group_left' => W::ev('Member left the group', 'A member leaves a group: the group administrators are told.', ['owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'cross']),
        'group_declined' => W::ev('Invitation declined', 'Someone declines an invitation to a group: the person who invited them is told.', ['owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'cross']),
        'settings_changed' => W::ev('WIMSChat settings changed', 'A Super Admin saves the WIMSChat settings (file limits, edit and delete windows, retention, groups). This goes to the roles and people chosen here, not to chat members.', ['roles' => ['Super Admin'], 'enabled' => false, 'icon' => 'cog', 'subject' => 'WIMSChat settings changed', 'body' => 'The WIMSChat settings were changed by #sender.']),
    ],
    'tags' => [
        W::tag('sender', 'Chat', 'Sender', 'The person who wrote, nudged or changed the group.', 'Abdulhakeem Nassir'),
        W::tag('conversation', 'Chat', 'Conversation', 'The group name, or the sender for a direct chat.', 'Mombasa Site Team'),
        W::tag('preview', 'Chat', 'Message preview', 'The first words of the message (empty for nudges and group changes).', 'Please send the delivery note'),
    ],
];
