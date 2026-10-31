# Reputation: provider costs and a stand-alone plan proposal

Verification date: **2026-10-31**. Provider prices come from the official pages listed below. Anything not on an official page is marked
**Not Verified** and is not used to claim a margin. Token counts per reply are an **estimate** (to be replaced by the measured usage in
the platform's AI cost tracking before any price is approved).

## Verified provider prices
| Item | Price | Unit | Source |
|---|---|---|---|
| SMS to Egypt (Twilio, outbound, international number) | **$0.3959** | per segment, plus possible carrier fees; $0.001 per failed message | https://www.twilio.com/en-us/sms/pricing/eg ("Pricing current as of October 2026") |
| Gemini 3.8 Flash (the `gemini-flash-latest` alias the platform uses; confirm which model the alias resolves to) | input $0.75, output incl. thinking $3.75 per 1M tokens through 2026-12-31; **$1.50 / $7.50 from 2027-01-01** | per 1M tokens | https://ai.google.dev/gemini-api/docs/pricing |
| Gemini 3.5 Flash-Lite | input $0.30, output $2.50 | per 1M tokens | same |
| Gemini 3.1 Flash-Lite | input $0.25, output $1.50 | per 1M tokens | same |
| Gemini 2.5 Flash-Lite | input $0.10, output $0.40 | per 1M tokens | same |
| WhatsApp (UltraMsg instance or Meta Cloud API conversation fees), e-mail sending, Google Business API, hosting and support | **Not Verified** | | not checked against an official invoice |

## What one reply and one request cost
- **AI reply** (estimate: about 1,000 input tokens and 600 output tokens including thinking): about **$0.003** now and **$0.006** after the 2027 price change on Gemini 3.8 Flash; about $0.0015 on Gemini 3.1 Flash-Lite.
- **SMS review request to Egypt**: an Arabic message is split into segments of about 70 characters, so a 150 to 250 character message is 3 to 4 segments: **about $1.19 to $1.58 per request**. 100 SMS cost about $120 to $160, more than any plan below. SMS therefore cannot be included in a flat plan.

## Margin check of the proposal in COMPETITOR_BENCHMARK.md (variable AI cost only, WhatsApp/e-mail/infra Not Verified)
| Plan | Price / month | AI replies / month | AI cost now | AI cost from 2027 | Share of price |
|---|---|---|---|---|---|
| Starter | $15 | 60 | $0.18 | $0.36 | 1.2% to 2.4% |
| Pro | $39 | 250 | $0.75 | $1.50 | 1.9% to 3.8% |
| Agency | $99 | 1000 | $3.00 | $6.00 | 3% to 6% |

AI cost is small. The real risks are (1) SMS, (2) WhatsApp/e-mail volume once those costs are verified, and (3) fixed costs (infrastructure, support, payment fees), which are not in this table.

## Recommendations
1. **Keep SMS out of the plans.** It is now off by default (cap 0 per plan in Admin > Reputation limits). If it is sold, charge it from the wallet per message at no less than about **$1.7 per 3-segment Arabic message** (cost $1.19 plus a margin and carrier fees), or prefer WhatsApp/e-mail for Egypt.
2. **Use a cheaper model for review replies if quality holds.** Moving from Gemini 3.8 Flash to Flash-Lite cuts the AI cost by 2.5x to 4x. This needs a side-by-side quality check on Arabic replies first (owner decision).
3. **Do not approve prices until** the measured AI cost per reply, the WhatsApp cost per request and the fixed cost per customer are known.
4. Prices stay in Plans Studio. Limits per plan are in Admin > Reputation limits. A stand-alone paid reputation plan (own subscription and wallet billing) is **not built**: it needs the owner's approval of the price and billing design.
