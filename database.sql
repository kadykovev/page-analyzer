CREATE TABLE urls (
    id          bigint  PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    name        varchar(255),
    created_at  timestamp
);