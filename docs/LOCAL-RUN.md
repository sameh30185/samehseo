# Local compose notes

Minimum: postgres + redis (+ api).

    docker compose up -d postgres redis
    docker compose up -d api

Web later: from apps/web run the Next.js dev server with NEXT_PUBLIC_API_URL pointing at the API.

WordPress later: mount apps/connector/sameh-connector into a WordPress container plugins directory, activate SAMEH Connector, call health then signed discover.

This box may not have Docker installed; use host Postgres instead when needed.
