ALTER TABLE broadcasts ADD COLUMN team_channel_id INTEGER CHECK (team_channel_id IS NULL OR team_channel_id < 0);
CREATE UNIQUE INDEX one_channel_post ON jobs(broadcast_id) WHERE kind='channel_post';
