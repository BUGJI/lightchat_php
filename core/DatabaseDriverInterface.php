<?php
/**
 * 数据库驱动接口
 * 定义统一的数据库操作方法
 */
interface DatabaseDriverInterface {
    
    /**
     * 连接数据库
     * @return bool
     */
    public function connect();
    
    /**
     * 断开连接
     */
    public function disconnect();
    
    /**
     * 执行查询（SELECT）
     * @param string $sql SQL语句或查询标识
     * @param array $params 参数
     * @return array 查询结果
     */
    public function query($sql, $params = []);
    
    /**
     * 执行一条语句（INSERT/UPDATE/DELETE）
     * @param string $sql SQL语句或操作标识
     * @param array $params 参数
     * @return int 影响的行数或最后插入ID
     */
    public function execute($sql, $params = []);
    
    /**
     * 插入数据
     * @param string $table 表名
     * @param array $data 数据
     * @return int|string 最后插入ID
     */
    public function insert($table, $data);
    
    /**
     * 更新数据
     * @param string $table 表名
     * @param array $data 数据
     * @param array $where 条件
     * @return int 影响行数
     */
    public function update($table, $data, $where);
    
    /**
     * 删除数据
     * @param string $table 表名
     * @param array $where 条件
     * @return int 影响行数
     */
    public function delete($table, $where);
    
    /**
     * 获取单条记录
     * @param string $table 表名
     * @param array $where 条件
     * @param string $fields 字段
     * @return array|null
     */
    public function get($table, $where, $fields = '*');
    
    /**
     * 获取多条记录
     * @param string $table 表名
     * @param array $where 条件
     * @param string $fields 字段
     * @param string $order 排序
     * @param int $limit 限制
     * @return array
     */
    public function select($table, $where = [], $fields = '*', $order = '', $limit = 0);
    
    /**
     * 统计记录数
     * @param string $table 表名
     * @param array $where 条件
     * @return int
     */
    public function count($table, $where = []);
    
    /**
     * 开始事务
     */
    public function beginTransaction();
    
    /**
     * 提交事务
     */
    public function commit();
    
    /**
     * 回滚事务
     */
    public function rollback();

    /**
     * 检查是否在事务中
     * @return bool
     */
    public function inTransaction();
    
    /**
     * 获取最后插入ID
     * @return int|string
     */
    public function lastInsertId();
}