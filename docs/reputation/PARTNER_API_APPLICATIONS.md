# Booking.com and TripAdvisor review access: application drafts (to be filed by the account owner)

Status on 2026-10-31: **not filed.** Only the business owner can apply (company details, signature, their own Booking/TripAdvisor accounts).
What is verified and what is not:
- Verified: Tourfecto reads TripAdvisor through its Content API integration and today gets only the latest 5 reviews per location (platform limit, see `docs/TRIPADVISOR_SETUP.md`). Booking.com, Expedia and Trustpilot reviews can be imported from the CSV each platform exports (live feature "Import reviews from other sites").
- **Not Verified:** the exact requirements, fees and review-reading scope of Booking.com's Connectivity/partner programme. The review-specific documentation page returned 404 on 2026-10-31 (https://developers.booking.com/ lists Demand API, Connectivity APIs, Metasearch Connect and Data Portability API). Confirm on the portal before filing.
- Nothing here changes any OAuth scope, redirect URI, webhook or review-app setting. Do not change those while the Meta and Google verifications are pending.

## What Tourfecto would do with the access (use in both applications)
- Read-only: fetch the reviews of properties/tours that the customer owns and has connected, store them in the customer's own account, show them next to Google reviews, compute sentiment and topics, and notify the owner about negative or urgent reviews.
- Never publish, edit or delete anything on the platform. No scraping.
- One connection per customer, authorised by the customer; disconnecting stops the sync and the customer can delete imported reviews (data-deletion flow already exists for Meta; the same deletion request applies to this data).
- Data is isolated per customer account (tested), encrypted tokens, no sharing with third parties, no use for advertising.

## Facts to fill in before filing
| Field | Value |
|---|---|
| Legal company name and registration number | [OWNER TO FILL] |
| Country, address, contact person, phone | [OWNER TO FILL] |
| Website | https://tourfecto.pro |
| Privacy policy URL | [OWNER: the live privacy page URL] |
| Terms URL | [OWNER: the live terms page URL] |
| Data-deletion contact / URL | [OWNER TO FILL] |
| Expected volume (customers, reviews per month) | [OWNER TO FILL: do not guess] |
| Customer type | tourism operators and local businesses, mainly Egypt and the Arab region |

## Draft: Booking.com
Subject: Request for read access to guest reviews for our customers' properties

> We operate Tourfecto (https://tourfecto.pro), a SaaS platform for tourism and local businesses that manages online reputation. Our customers are property and tour operators who already hold Booking.com accounts. We request read-only access to the guest reviews of the properties each customer explicitly connects, so that they can see all their reviews in one place, receive alerts about negative reviews, and answer guests faster. We do not publish or modify anything on Booking.com, we store reviews only inside the customer's own account, and each customer can disconnect and delete the data at any time. Please tell us which programme and agreement apply to this use, the technical and security requirements, and any costs.

## Draft: TripAdvisor
Subject: Request for access beyond the 5 latest reviews per location

> We use the TripAdvisor Content API in Tourfecto (https://tourfecto.pro) to show our customers the reviews of their own locations. The API returns only the 5 latest reviews, which is not enough to analyse sentiment or detect trends. We would like to know which TripAdvisor programme provides access to the full review history of locations owned by our customers (with the owner's consent), and what the requirements and costs are. The access is read-only, per customer, stored in the customer's own account, and removable on request.

## After approval (engineering, not started)
Sync job in the style of the Google sync (schedule, de-duplication on `source_platform` + `external_review_id`, negative-review alert, per-plan limits), credentials stored encrypted per customer, a real sandbox test before enabling for customers.

## Update 2026-10-31: what the official developer sites say (fetched that day)
- **Trustpilot** (https://developers.trustpilot.com/authentication): public APIs need only your API key (Client ID), no OAuth and no partner approval. The public endpoint `GET https://api.trustpilot.com/v1/business-units/{businessUnitId}/all-reviews` lists a business unit's reviews with paging (it does not return customer emails or order ids). Private reviews and replying need OAuth 2.0 with the API key and secret. So a Trustpilot connector can be built as soon as the owner provides an API key and the business unit id of each customer's Trustpilot page. Not built yet.
- **Booking.com** (https://developers.booking.com/connectivity/docs): the Connectivity APIs "retrieve and respond to guest reviews", but only for Connectivity Partners, who must meet specific onboarding requirements. The review-specific pages returned 404, so the exact requirements are Not Verified. Application needed (draft above).
- **Expedia / Vrbo** (https://developers.expediagroup.com/): the portal mentions property reviews for Vrbo; I could not verify a review API for Expedia hotels. Not Verified.
- **TripAdvisor**: the Content API we already use returns only the latest 5 reviews.
