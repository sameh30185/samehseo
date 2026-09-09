# جرد الميزات — SAMEH SEO OS (القديم) → 12.1 FINAL

المصدر: توثيق الترحيل من 11.9.6 (`docs/MIGRATION-FROM-11.9.6.md`) + مسح مسارات الإضافة/الأرشيف في المستودع.

## ما نُقل إلى Core المركزي (cpanel-edition)

| مجموعة | أصناف 11.9.x التقريبية | حالة 12.1 FINAL |
|--------|-------------------------|-----------------|
| Project Brain / Truth | project-truth, business-brain, project-discovery | ✅ CRUD عربي + موافقة حقائق |
| Workforce / Agents | workforce-registry, ai-employee, company-orchestrator | ✅ وكلاء حتميون + طابور Local AI |
| Director / Missions | director-runtime, mission-engine | ✅ مهام + مسار Hermes عبر الطابور + Rules-only |
| Local AI / Model Router | local-ai, model-router | ✅ Worker Node + Ollama/Hermes (Owner Windows فقط) |
| Quality / QA / Policy | quality-gate, qa, policy-engine | ✅ بوابات مصنع صارمة + QA |
| Preview / Approval / Execute | action-contract, preview-builder, execution-manager | ✅ خط أنابيب مكتوب الأنواع |
| Verify / Rollback | verification-engine, rollback-manager | ✅ تحقق + تراجع مسودات |
| Growth / Intelligence | growth-intelligence, opportunities, competitor | ✅ اكتشاف v2 + خصم تكرار + رسالة أدلة |
| Page Factory | production-factory, template-engine, batch-gate | ✅ قوالب ذهبية عربية + حظر عند فشل QA |
| Content / Media / Rank Math | content-engine, rank-math-schema | ✅ إجراءات مكتوبة + اكتشاف وسائط |
| GSC / Ads / Telegram | search-console, google-ads, telegram | ⚠ CSV/Sitemap حقيقي؛ OAuth/Telegram غير متصل بصدق أو محذوف من القائمة |
| Connector رفيع | connector-manager, read/write executors | ✅ إضافة WP HMAC + discover/v2 |

## ما بقي على Connector فقط

- Health / Discover / Discover v2
- create_draft, update_draft, get_post, update_rank_math, change_post_status
- أسرار الموقع وHMAC

## مبادئ محفوظة

- READ_ONLY افتراضي + Kill Switch
- لا بيانات وهمية — «غير متصل» عند غياب التكامل
- Core لا يستدعي 127.0.0.1/Ollama أبداً
- هجرات SQL إضافية فقط
