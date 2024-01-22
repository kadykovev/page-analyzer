CREATE TABLE IF NOT EXISTS urls (
    id          bigint  PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    name        varchar(255),
    created_at  timestamp
);
CREATE TABLE IF NOT EXISTS url_checks (
    id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    url_id bigint REFERENCES urls (id),
    status_code integer,
    h1 text,
    title text,
    description text,
    created_at timestamp
);