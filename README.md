# Timeprints Web Generator

This PHP application generates DOCX/PDF documents from Word templates by filling placeholders with data from a MySQL database and AI-parsed text.  
It also enriches each document with **weather forecasts** for the relevant date and location.

---

## Overview

When a user selects a project/job from the web interface, the app:

1. Retrieves project data from the database (`projects`, `jobs`, `ort`, `projektdetails`, etc.).
2. Calls the **OpenAI API** (`gpt-4.1-mini`) to parse unstructured project text (e.g., *Ort*, *Projektdetails*) into structured placeholders such as  
   `${Ansprechpartnerin}`, `${technik__}`, `${farbraum}`, `${motiv}`, etc.
3. Fetches **weather forecast data** from [Open-Meteo](https://open-meteo.com/) for the corresponding date and coordinates.
   - If the date is **today or in the future**, the app uses that day’s forecast.
   - If the date is **in the past**, it automatically falls back to **tomorrow’s forecast** for the same location.
4. Fills all placeholders in the DOCX template using **PhpWord** and outputs both `.docx` and `.pdf` files.

---

## Features

| Feature | Description |
|----------|-------------|
| **Database integration** | Reads project and job information from MySQL. |
| **AI parsing** | Uses OpenAI Responses API to interpret free-text fields into structured data. Includes retries and graceful fallback on 5xx/timeout errors. |
| **Weather enrichment** | Fetches daily/hourly forecasts from Open-Meteo, extracting temperature highs/lows, wind, sunshine hours, and rain probability. |
| **Graceful degradation** | If OpenAI or weather APIs are unreachable, generation continues with blank values (no crash, no raw placeholders). |
| **Custom DOCX templates** | Any `${placeholder}` in the template will be replaced by the corresponding field value. |
| **PDF export** | The generated Word document is automatically converted to PDF. |

---

## File Structure

```
timeprints_web/
│
├── public/
│   ├── index.php           # Main web entry point / form
│   ├── generate.php        # Generates DOCX/PDF from selected job
│
├── lib/
│   ├── ai.php              # Handles OpenAI API calls and parsing logic
│   ├── db.php              # Database connection and query helpers
│   ├── weather.php         # Fetches forecast from Open-Meteo API
│   └── utils.php           # Misc. helpers and template processing
│
├── templates/
│   └── doc_template.docx   # Word template with placeholders
│
├── config/
│   └── openai.php          # Model, endpoint, API key, timeout
│
├── vendor/                 # Composer dependencies (PhpWord, etc.)
└── README.md               # You are here
```

---

## Weather System

Implemented in `lib/weather.php`.

### Forecast logic
- Always requests both **daily** and **hourly** data from Open-Meteo:
  - `temperature_2m`, `precipitation_probability`, `wind_speed_10m`, `shortwave_radiation`
- Uses the given start/end times to compute in-window stats:
  - **`${hoch}`** → max temperature  
  - **`${tief}`** → min temperature  
  - **`${wind}`** → max wind speed (km/h)  
  - **`${sonne_max}`** → sunshine hours (either hourly proxy or daily total)  
  - **`${regen%}`** → max precipitation probability
- If no hourly data match the time window, it falls back to daily aggregates.

### Date fallback
- Past date → tomorrow’s forecast  
- Future or today → that date’s forecast  
- No crash if API fails; placeholders become empty strings.

---

## OpenAI Parsing

Implemented in `lib/ai.php`.

- Uses the `/v1/responses` endpoint with `gpt-4.1-mini`.
- Input: raw text fields from DB (e.g., `ort`, `projektdetails`).
- Output: associative array with structured values for placeholders.
- Includes:
  - 3 automatic retries on transient (429, 503, etc.) errors.
  - Graceful fallback returning blank fields if OpenAI is unavailable.

---

## Environment Variables

Set in `.env` or system environment:

| Variable | Description |
|-----------|-------------|
| `OPENAI_API_KEY` | Required — your OpenAI API key. |
| `AI_PARSE_ENABLED` | Optional (`true`/`false`) — disables AI parsing if set to `false`. |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Database credentials. |

---

## Local Development

1. **Install dependencies**

   ```bash
   composer install
   ```

2. **Configure environment**

   Copy `.env.example` → `.env` and add your keys.

3. **Run locally**

   ```bash
   php -S localhost:8000 -t public
   ```

4. **Generate a document**

   - Open [http://localhost:8000](http://localhost:8000)
   - Choose a project and click **Generate**

---

## Template Placeholders

Supported placeholders include (extendable):

| Category | Example Placeholders |
|-----------|----------------------|
| **Project data** | `${projektname}`, `${kunde}`, `${datum}`, `${start}`, `${end}` |
| **AI-parsed fields** | `${Ansprechpartnerin}`, `${Ansprechpartnerin_tel}`, `${technik__}`, `${farbraum}`, `${motiv}` |
| **Weather data** | `${hoch}`, `${tief}`, `${wind}`, `${sonne_max}`, `${regen%}` |

Add new `${key}` placeholders to your Word template, and simply populate `$vars['key']` in `generate.php` to fill them.

---

## Error Handling

- **OpenAI 503 / network timeout:** Retries automatically; if still failing, placeholders left blank.
- **Weather API unreachable:** Weather fields blank; generation continues.
- **Missing DB fields:** Replaced with empty strings (no raw `${…}` shown in final document).

---

## License

MIT License — use, adapt, and extend freely.

---

## Author

**Shachar Heyman**  
Berlin, Germany  
[linkedin.com/in/shachar-heyman-861a812b4](https://linkedin.com/in/shachar-heyman-861a812b4)  
[github.com/AlbaChagal](https://github.com/AlbaChagal)
