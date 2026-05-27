# TESTING_CHECKLIST

## Start here

- Run the SQL seeds/migrations as needed:
  - `migrations/permissions_and_profile.sql`
  - `migrations/demo_accounts_and_auth.sql`
  - `migrations/one_to_one_volunteer_elderly.sql`
- Open `login.html`.
- Hosted URL format: `https://YOUR_DOMAIN/Meetting_Points/login.html`.

## Test volunteer login

- Login with:
  - Email / username: `volunteer.demo@hiburim.local`
  - Password: `Volunteer123!`
- Expected redirect: `index.html`.
- Expected menu:
  - בית
  - דיווחים
  - צ'אט דיווח
  - יומן
  - פרופיל
  - אודות
  - התנתקות
- Confirm home screen, about screen and calendar screen are visible and match the original UI.
- Confirm volunteer can create a report only for assigned elderly.
- Confirm `reports.html` returns only reports created by this volunteer.
- Confirm `assignments.html#elderly` shows at most one elderly assigned to this volunteer.
- Try opening `statusAI.html` directly and confirm the user is blocked or redirected.
- Try direct API access to `api/ai_report.php` and confirm JSON unauthorized/forbidden response.

## Test manager login

- Logout.
- Login with:
  - Email / username: `manager.demo@hiburim.local`
  - Password: `Manager123!`
- Expected redirect: `index.html`.
- Expected menu:
  - בית
  - דיווחים
  - תובנות
  - דוחות AI
  - יומן
  - פרופיל
  - אודות
  - התנתקות
- Confirm home screen, insights screen and about screen are visible and match the original UI.
- Confirm manager sees organization reports only.
- Confirm manager can open AI insights.
- Confirm manager can manage report statuses.
- Confirm assigning a volunteer to an elderly person replaces previous assignments, so each volunteer has only one elderly and each elderly has only one volunteer.
- Confirm manager cannot see data from another organization.

## Unauthenticated access

- Logout.
- Try opening `chatbot.html`, `reports.html`, `statusAI.html`, `assignments.html`, and `profile.html` directly.
- Expected: redirect to `login.html`.
- Try direct API access to protected endpoints such as `api/reports.php`, `api/profile.php`, `api/assignments.php`.
- Expected JSON: `success=false` and `error=unauthorized`.

## Profile

- Open profile page after login.
- Update phone/email/name.
- Refresh and confirm changes persist.
- Try invalid email/phone.
- Confirm user cannot change role or `organization_id`.
- If password auth exists, try changing password with wrong old password and then correct old password.

## Support chatbot

- Open support chatbot on desktop.
- Ask: “איך יוצרים דיווח חדש?”
- Ask: “איך מעדכנים סטטוס?”
- Ask unrelated question and confirm it redirects politely.
- Try reporting an elderly case inside support widget and confirm it redirects to the reporting flow.
- Open the widget on mobile width and confirm it keeps margins and does not cover the app in a broken way.

## Urgency recommendation

- Report loneliness case and confirm urgency suggestion is reasonable.
- Report gas smell and confirm critical urgency + emergency warning.
- Report unclear case like “יש בעיה” and confirm the system asks for more details.
- Confirm final selected urgency is saved in DB.
- Confirm `reports.html` reflects the saved urgency.
- Confirm managers can still open `statusAI.html` and volunteers are redirected away from it.

## Controlled reporting chatbot

- Loneliness case: “הקשישה מרגישה בודדה ומבקשת שיחת טלפון או ביקור”.
  Expected: category/need type is emotional or loneliness, no over-questioning if clear, urgency is recommended and must be confirmed.
- Unclear case: “יש בעיה”.
  Expected: bot asks one targeted clarification and does not create or summarize a report.
- Unrelated case: “אני רוצה להזמין פיצה”.
  Expected: bot redirects to elderly-care reporting purpose and does not save the message as report context.
- Maintenance case: broken window or water leak.
  Expected: bot asks whether it affects safety/basic living conditions if needed, then recommends urgency.
- Medical case: pain, missing medicine, confusion, or follow-up need.
  Expected: bot identifies medical/cognitive category and recommends medium/high/critical according to risk.
- Critical gas smell case: “יש ריח גז בבית של הקשיש”.
  Expected: urgency is `קריטית`, emergency warning appears, and saving remains allowed only after confirmations.
- User rejects proposed description.
  Expected: bot returns to description editing and does not keep the rejected proposed description.
- User changes recommended urgency.
  Expected: final summary and saved DB urgency use the changed value.
- Image upload and confirmation.
  Expected: image analysis must be confirmed/corrected before it is merged into the report.
- Final report creation.
  Expected: report is created only after meaningful description, confirmed urgency, and explicit final summary approval.
- Report appears in `reports.html`.
  Expected: report content includes description, category/need type, urgency, urgency reason, image analysis if any, and immediate-action field.
- Urgency appears in AI analytics.
  Expected: `statusAI.html`/AI analytics can still read the urgency correctly, including `קריטית`.

## Chatbot guardrails

- User: “מה עיר הבירה של צרפת?”
  Expected: bot refuses/redirects to elderly report purpose.
- User: “זה הבעיה של הקשיש”
  Expected: bot still does not create report, asks for concrete elderly need.
- User: “הקשיש לא זוכר באיזו עיר הוא גר ונראה מבולבל”
  Expected: valid report, category cognitive/medical, urgency high or medium-high.
- User: “הקשיש שאל אותי מה עיר הבירה של צרפת כי הוא מבולבל ולא זוכר דברים בסיסיים”
  Expected: valid report only because the real issue is confusion/memory decline, not because of the trivia question.
- User: “מי ראש הממשלה של ישראל?”
  Expected: bot redirects and does not call the general question a report.
- User: “תפתור לי תרגיל”
  Expected: bot redirects and does not advance stage.
- User: “כתוב לי קוד”
  Expected: bot redirects and does not advance stage.
- User: “מה מזג האוויר?”
  Expected: bot redirects and does not advance stage.
- User: “ספר בדיחה”
  Expected: bot redirects and does not advance stage.

## Existing flows

- Upload image in the reporting chatbot if supported.
- Confirm report and verify it appears in `reports.html`.
- Open the restored AI reports tab as NGO manager.
- Change report status as NGO manager and verify `reports.html` updates.
