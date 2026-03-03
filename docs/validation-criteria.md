# Schema Validation Criteria

These rules are enforced by the server-side validator (`Schema_Validator`). Missing required fields trigger errors. Missing recommended fields trigger warnings.

## Supported Schema Types

BlogPosting / Article / NewsArticle
- Required: `headline`, `datePublished`, `author`, `publisher`
- Recommended: `image`, `description`, `mainEntityOfPage`

Review
- Required: `itemReviewed`
- Recommended: `reviewBody`, `reviewRating`, `author`, `datePublished`

FAQPage
- Required: `mainEntity` (at least one Question with `name` and `acceptedAnswer.text`)
- Recommended: `inLanguage`

HowTo
- Required: `step` (at least one step with `text`)
- Recommended: `image`, `totalTime`

ItemList
- Required: `itemListElement` (at least one ListItem)
- Recommended: `name`, `description`

VideoObject
- Required: `name`, `thumbnailUrl`, `uploadDate`, and at least one of `contentUrl` or `embedUrl`
- Recommended: `description`, `duration`

Product
- Required: `name`
- Recommended: `brand`, `image`, `description`

Trip
- Required: `name`
- Recommended: `itinerary`, `image`

Place
- Required: `name`
- Recommended: `address`, `geo`, `image`

Airline
- Required: `name`
- Recommended: `iataCode`, `url`

## Core Graph Nodes (Warnings Only)

WebPage
- Required: `url`, `name`

WebSite
- Required: `url`, `name`

Organization
- Required: `name`, `url`

BreadcrumbList
- Required: `itemListElement`
