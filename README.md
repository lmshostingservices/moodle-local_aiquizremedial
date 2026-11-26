# AI Quiz Remedial Learning (local_aiquizremedial)

Generates AI revision modules for questions a learner gets wrong or leaves blank. It works with standard Moodle quizzes and, if installed, with AI Knowledge Check activities. The two sources are independent: either can be used without the other.

- Learners: banners on the quiz, quiz review, course and Knowledge Check pages, plus "My revision modules" in the course navigation.
- Teachers/managers: *Remedial learning report* (course "More" menu, the quiz page button, or Site administration > Reports), with full filtering and export.
- Cron: `\local_aiquizremedial\task\process_jobs` (every 2 minutes).
- Backfill: `php local/aiquizremedial/cli/rescan.php --days=14 [--courseid=N] [--execute]`

Requires Moodle 4.0+.

## Bring your own AI

With an LMS Labs Bring your own AI licence, set Text and Images to Moodle AI or your own key, and Voice to your own key, in AI Grader Central Config (`local_aiconfig`). Each part of a revision module then runs on your site's own AI and uses no credits: lessons (Text), narration (Voice), pictures (Images) and translations (Text and Voice). Parts left on LMS Labs credits still use credits. The plugin sends the same prompts as the LMS Labs service and checks the AI's answers the same way.

## Licence

GNU GPL v3 or later.
